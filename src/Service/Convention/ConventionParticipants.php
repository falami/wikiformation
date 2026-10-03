<?php

declare(strict_types=1);

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, DossierInscription, Inscription, Utilisateur};
use App\Enum\{ModeFinancement, StatusInscription, StatusSession};
use Doctrine\ORM\{EntityManagerInterface, QueryBuilder};
use Doctrine\DBAL\LockMode;

/** Prépare la sélection sans écrire ; persiste les inscriptions après validation du formulaire. */
final class ConventionParticipants
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function eligibleQuery(ConventionContrat $convention): QueryBuilder
    {
        $qb = $this->em->getRepository(Utilisateur::class)->createQueryBuilder('u')->distinct()
            ->leftJoin('u.utilisateurEntites', 'membership', 'WITH', 'membership.entite = :entite')
            ->leftJoin('u.inscriptions', 'existing', 'WITH', 'existing.session = :session AND existing.entite = :entite')
            ->leftJoin('u.inscriptions', 'history', 'WITH', 'history.entite = :entite')
            ->leftJoin('history.session', 'historySession', 'WITH', 'historySession.entite = :entite')
            ->andWhere('(u.entite = :entite OR membership.id IS NOT NULL OR existing.id IS NOT NULL)')
            ->andWhere('(membership.id IS NULL OR membership.status != :suspended)')
            ->setParameter('suspended', 'suspended')
            ->setParameter('entite', $convention->getEntite())
            ->setParameter('session', $convention->getSession())
            ->orderBy('u.nom', 'ASC')->addOrderBy('u.prenom', 'ASC');
        if ($company = $convention->getEntreprise()) {
            $qb->andWhere('(u.entreprise = :company OR existing.entreprise = :company OR (u.entreprise IS NULL AND (membership.roles LIKE :learnerRole OR historySession.id IS NOT NULL)))')
                ->setParameter('company', $company)->setParameter('learnerRole', '%"TENANT_STAGIAIRE"%');
        } elseif ($learner = $convention->getStagiaire()) {
            $qb->andWhere('u.id = :learner')->setParameter('learner', $learner->getId());
        } else {
            $qb->andWhere('1 = 0');
        }
        return $qb;
    }

    /** @param iterable<Utilisateur> $learners */
    public function prepare(ConventionContrat $convention, iterable $learners, bool $replaceFreeNames): void
    {
        $this->assertContext($convention);
        $selection = [];
        foreach ($learners as $learner) {
            if (!$learner instanceof Utilisateur || !$learner->getId()) {
                throw new \DomainException('Sélectionnez des stagiaires enregistrés dans cet organisme.');
            }
            $selection[$learner->getId()] = $learner;
        }
        if ($selection) {
            $eligible = $this->eligibleQuery($convention)->select('u.id')->andWhere('u.id IN (:ids)')
                ->setParameter('ids', array_keys($selection))->getQuery()->getSingleColumnResult();
            if (count($eligible) !== count($selection)) {
                throw new \DomainException('Un stagiaire sélectionné ne correspond pas à cet organisme ou au destinataire de la convention.');
            }
        }
        $inscriptions = [];
        foreach ($selection as $learner) {
            $inscription = $this->em->getRepository(Inscription::class)->findOneBy([
                'session' => $convention->getSession(), 'stagiaire' => $learner,
            ]);
            if ($inscription && ($inscription->getEntite() !== $convention->getEntite()
                || $inscription->getStatus() === StatusInscription::ANNULE
                || ($convention->getEntreprise() && $inscription->getEntreprise() && $inscription->getEntreprise() !== $convention->getEntreprise()))) {
                throw new \DomainException(sprintf('L’inscription de %s %s est annulée ou rattachée à un autre destinataire. Corrigez-la depuis la session.', $learner->getPrenom(), $learner->getNom()));
            }
            if ($inscription && $convention->getEntreprise() && !$inscription->getEntreprise()) {
                // Le choix explicite rattache uniquement cette inscription au client ;
                // la fiche globale et les autres sessions du stagiaire restent intactes.
                foreach ($inscription->getConventionContrats() as $document) {
                    if ($document !== $convention && ($document->isSigned() || $document->getEntreprise() !== $convention->getEntreprise())) {
                        throw new \DomainException('Cette inscription est déjà couverte par une convention signée ou un autre destinataire. Vérifiez le dossier avant de la rattacher.');
                    }
                }
                foreach ([...$inscription->getDevis(), ...$inscription->getFactures()] as $document) {
                    if ($document->getEntite() !== $convention->getEntite() || $document->getEntrepriseDestinataire() !== $convention->getEntreprise()) {
                        throw new \DomainException('Cette inscription est déjà liée à un devis ou une facture d’un autre destinataire. Vérifiez son financement.');
                    }
                }
                $inscription->setEntreprise($convention->getEntreprise())->setModeFinancement(ModeFinancement::ENTREPRISE);
            }
            $inscriptions[] = $inscription ?? (new Inscription())->setEntite($convention->getEntite())
                ->setSession($convention->getSession())->setStagiaire($learner)->setEntreprise($convention->getEntreprise())
                ->setModeFinancement($convention->getEntreprise() ? ModeFinancement::ENTREPRISE : ModeFinancement::INDIVIDUEL);
        }
        foreach ($convention->getInscriptions()->toArray() as $old) {
            $convention->removeInscription($old);
        }
        foreach ($inscriptions as $inscription) {
            $convention->addInscription($inscription);
        }
        if ($replaceFreeNames) {
            $names = [];
            foreach ($selection as $learner) {
                $names[] = self::nameKey($learner->getPrenom().' '.$learner->getNom());
                $names[] = self::nameKey($learner->getNom().' '.$learner->getPrenom());
            }
            $convention->setParticipantsLibres(implode("\n", array_filter($convention->getParticipantsLibresListe(),
                static fn(string $name): bool => !in_array(self::nameKey($name), $names, true))));
        }
    }

    /** Le contrôleur détient déjà le verrou sur la convention dans la transaction. */
    public function persist(ConventionContrat $convention, Utilisateur $actor): void
    {
        $this->assertContext($convention);
        if (!$this->em->getConnection()->isTransactionActive()) {
            throw new \LogicException('La mise à jour des participants nécessite une transaction.');
        }
        $session = $convention->getSession();
        $this->em->lock($session, LockMode::PESSIMISTIC_WRITE);
        $active = (int) $this->em->getRepository(Inscription::class)->createQueryBuilder('i')
            ->select('COUNT(i.id)')->andWhere('i.session = :session')->andWhere('i.status != :cancelled')
            ->setParameter('session', $session)->setParameter('cancelled', StatusInscription::ANNULE)->getQuery()->getSingleScalarResult();
        $new = count(array_filter($convention->getInscriptions()->toArray(), static fn(Inscription $i): bool => !$i->getId()));
        $unassigned = max(0, $convention->getEffectifTotal() - $convention->getInscriptions()->count());
        if ($session->getCapacite() < 1 || $active + $new + $unassigned > $session->getCapacite()) {
            throw new \DomainException('La capacité de la session est insuffisante pour cet effectif. Ajustez la capacité ou la sélection.');
        }
        foreach ($convention->getInscriptions() as $inscription) {
            if (!$inscription->getId()) {
                $inscription->setCreateur($actor);
                $session->addInscription($inscription);
                $this->em->persist($inscription);
            }
            if (!$inscription->getDossier()) {
                $dossier = (new DossierInscription())->setEntite($convention->getEntite())->setCreateur($actor)->setInscription($inscription);
                $inscription->setDossier($dossier);
                $this->em->persist($dossier);
            }
            $convention->getDevis()?->addInscription($inscription);
        }
    }

    private function assertContext(ConventionContrat $convention): void
    {
        if ($convention->isSigned()) {
            throw new \DomainException('Une convention signée ne peut plus être modifiée.');
        }
        $entity = $convention->getEntite();
        $session = $convention->getSession();
        if (!$entity || !$session || $session->getEntite() !== $entity
            || ($convention->getEntreprise() && $convention->getEntreprise()->getEntite() !== $entity)
            || ($convention->getDevis() && $convention->getDevis()->getEntite() !== $entity)) {
            throw new \DomainException('Le dossier doit appartenir au même organisme.');
        }
        if ($session->getStatus() === StatusSession::CANCELED) {
            throw new \DomainException('La session est annulée. Rétablissez-la avant de modifier les participants.');
        }
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
