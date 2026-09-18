<?php

namespace App\Service\Convention;

use App\Entity\{ConventionContrat, Devis, DossierInscription, Entite, Inscription, Session, Utilisateur};
use App\Enum\{DevisStatus, ModeFinancement, StatusInscription, StatusSession};
use App\Service\Sequence\{ConventionContratNumberGenerator, SessionNumberGenerator};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/** Crée la session éventuelle, les inscriptions et la convention dans une seule transaction. */
final class DevisConventionCreator
{
    public function __construct(
        private EntityManagerInterface $em,
        private ConventionContratNumberGenerator $conventionNumbers,
        private SessionNumberGenerator $sessionNumbers,
    ) {}

    /** @param list<Utilisateur> $stagiaires */
    public function create(Devis $devis, Session $session, array $stagiaires, Utilisateur $createur, ?string $conditions): ConventionContrat
    {
        $entite = $devis->getEntite();
        if (!$entite || !$entite->getId() || !$devis->getId()) {
            throw new \DomainException('Le devis doit être enregistré avant de créer une convention.');
        }
        if ($devis->getStatus() === DevisStatus::CANCELED) {
            throw new \DomainException('Un devis annulé ne peut pas être transformé en convention.');
        }
        $entreprise = $devis->getEntrepriseDestinataire();
        $destinataire = $devis->getDestinataire();
        if (($entreprise === null) === ($destinataire === null) || $devis->getProspect()) {
            throw new \DomainException('Le devis doit être adressé à une entreprise ou à un stagiaire. Convertissez le prospect en client si nécessaire.');
        }
        if ($entreprise && !$this->sameEntity($entreprise->getEntite(), $entite)) {
            throw new \DomainException('L’entreprise du devis appartient à un autre organisme.');
        }
        if (!$this->sameEntity($session->getEntite(), $entite)
            || !$session->getFormation()
            || !$this->sameEntity($session->getFormation()->getEntite(), $entite)
            || !$session->getSite()
            || !$this->sameEntity($session->getSite()->getEntite(), $entite)) {
            throw new \DomainException('Choisissez une session, une formation et un lieu appartenant à cet organisme.');
        }
        if ($devis->getFormation() && $devis->getFormation()->getId() !== $session->getFormation()->getId()) {
            throw new \DomainException('La session doit correspondre à la formation du devis.');
        }
        if ($session->getStatus() === StatusSession::CANCELED) {
            throw new \DomainException('Cette session est annulée.');
        }
        $participants = [];
        foreach ($stagiaires as $stagiaire) {
            if (!$stagiaire instanceof Utilisateur || !$stagiaire->getId() || !$this->belongsTo($stagiaire, $entite)) {
                throw new \DomainException('Tous les stagiaires doivent appartenir à cet organisme.');
            }
            $participants[$stagiaire->getId()] = $stagiaire;
        }
        if (!$participants) {
            throw new \DomainException('Sélectionnez au moins un stagiaire.');
        }
        if ($destinataire && (count($participants) !== 1 || !isset($participants[$destinataire->getId()]))) {
            throw new \DomainException('Une convention individuelle doit couvrir le stagiaire destinataire du devis.');
        }
        if (!$session->getId()) {
            if ($session->getJours()->isEmpty()) {
                throw new \DomainException('Ajoutez au moins un créneau à la nouvelle session.');
            }
            $slots = $session->getJours()->toArray();
            usort($slots, static fn($a, $b) => $a->getDateDebut() <=> $b->getDateDebut());
            $previousEnd = null;
            foreach ($slots as $slot) {
                if (!$slot->getDateDebut() || !$slot->getDateFin() || $slot->getDateFin() <= $slot->getDateDebut()
                    || ($previousEnd && $slot->getDateDebut() < $previousEnd)) {
                    throw new \DomainException('Les créneaux doivent avoir une fin après le début et ne pas se chevaucher.');
                }
                $previousEnd = $slot->getDateFin();
            }
        }

        return $this->em->wrapInTransaction(function () use ($devis, $session, $participants, $createur, $conditions, $entite, $entreprise, $destinataire) {
            $existing = [];
            if ($session->getId()) {
                // Sérialise les ajouts concurrents à la même session (unicité + capacité).
                $this->em->lock($session, LockMode::PESSIMISTIC_WRITE);
                $existing = $this->em->getRepository(Inscription::class)->findBy(['session' => $session]);
            }
            $byStagiaire = [];
            $activeCount = 0;
            foreach ($existing as $inscription) {
                $byStagiaire[$inscription->getStagiaire()->getId()] = $inscription;
                if ($inscription->getStatus() !== StatusInscription::ANNULE) {
                    ++$activeCount;
                }
            }
            $newCount = 0;
            foreach ($participants as $id => $stagiaire) {
                $inscription = $byStagiaire[$id] ?? null;
                if (!$inscription) {
                    ++$newCount;
                    continue;
                }
                if (!$this->sameEntity($inscription->getEntite(), $entite) || $inscription->getStatus() === StatusInscription::ANNULE) {
                    throw new \DomainException('Une inscription sélectionnée est annulée ou appartient à un autre organisme.');
                }
                if ($entreprise && $inscription->getEntreprise()?->getId() !== $entreprise->getId()) {
                    throw new \DomainException(sprintf('L’inscription de %s %s doit être rattachée à l’entreprise du devis avant la conversion.', $stagiaire->getPrenom(), $stagiaire->getNom()));
                }
            }
            if ($session->getCapacite() < 1 || $activeCount + $newCount > $session->getCapacite()) {
                throw new \DomainException('La capacité de la session est insuffisante pour les stagiaires sélectionnés.');
            }

            if (!$session->getId()) {
                $session->setCode($this->sessionNumbers->nextForEntite($entite->getId()))->setCreateur($createur);
                foreach ($session->getJours() as $jour) {
                    $jour->setEntite($entite)->setCreateur($createur);
                }
                $this->em->persist($session);
            }
            $convention = (new ConventionContrat())
                ->setDevis($devis)->setEntite($entite)->setCreateur($createur)->setSession($session)
                ->setEntreprise($entreprise)->setStagiaire($destinataire)
                ->setNumero($this->conventionNumbers->nextForEntite($entite->getId()))
                ->setConditionsFinancieres($conditions);

            foreach ($participants as $id => $stagiaire) {
                $inscription = $byStagiaire[$id] ?? null;
                if (!$inscription) {
                    $inscription = (new Inscription())->setEntite($entite)->setCreateur($createur)
                        ->setSession($session)->setStagiaire($stagiaire)->setEntreprise($entreprise)
                        ->setModeFinancement($entreprise ? ModeFinancement::ENTREPRISE : ModeFinancement::INDIVIDUEL);
                    $session->addInscription($inscription);
                    $this->em->persist($inscription);
                }
                if (!$inscription->getDossier()) {
                    $dossier = (new DossierInscription())->setEntite($entite)->setCreateur($createur)->setInscription($inscription);
                    $inscription->setDossier($dossier);
                    $this->em->persist($dossier);
                }
                $convention->addInscription($inscription);
                $devis->addInscription($inscription);
            }
            $session->addConventionContrat($convention);
            $this->em->persist($convention);
            return $convention;
        });
    }

    private function sameEntity(?Entite $candidate, Entite $entite): bool
    {
        return $candidate === $entite || ($candidate?->getId() !== null && $candidate->getId() === $entite->getId());
    }

    private function belongsTo(Utilisateur $user, Entite $entite): bool
    {
        if ($this->sameEntity($user->getEntite(), $entite)) {
            return true;
        }
        foreach ($user->getUtilisateurEntites() as $membership) {
            if ($this->sameEntity($membership->getEntite(), $entite)) {
                return true;
            }
        }
        return false;
    }
}
