<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation\{TrainingWorkflow, WorkflowParticipant};
use App\Entity\{DossierInscription, Inscription, Utilisateur, UtilisateurEntite};
use App\Enum\{ModeFinancement, StatusInscription, StatusSession};
use App\Service\Billing\BillingGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Import is initiated by an administrator or by their explicit workflow setting. */
final class WorkflowParticipantImporter
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly BillingGuard $billing, private readonly UserPasswordHasherInterface $hasher, private readonly WorkflowPlanner $planner) {}

    /** @return array{imported:int,pending:int} */
    public function import(TrainingWorkflow $workflow, Utilisateur $actor): array
    {
        $connection = $this->em->getConnection();
        $level = $connection->getTransactionNestingLevel();
        $existingInsertions = array_fill_keys(array_map('spl_object_id', $this->em->getUnitOfWork()->getScheduledEntityInsertions()), true);
        $writingParticipants = false;
        $connection->beginTransaction();
        try {
            $this->em->refresh($workflow, LockMode::PESSIMISTIC_WRITE);
            $entite = $workflow->getEntite(); $session = $workflow->getSession(); $convention = $workflow->getConvention();
            if (!$entite || !$session || !$convention || $session->getEntite()?->getId() !== $entite->getId() || $convention->getEntite()?->getId() !== $entite->getId()) throw new \DomainException('Dossier incohérent.');
            // Serializes enrollment capacity and yearly learner quota consumption.
            $this->em->refresh($session, LockMode::PESSIMISTIC_WRITE);
            if ($session->getStatus() === StatusSession::CANCELED) throw new \DomainException('La session est annulée.');
            $this->em->lock($entite, LockMode::PESSIMISTIC_WRITE);
            $rows = $this->em->getRepository(WorkflowParticipant::class)->findBy(['workflow' => $workflow], ['position' => 'ASC']);
            $pending = 0; $prepared = []; $newLearners = 0; $newEnrollments = 0; $seenEmails = [];
            foreach ($rows as $row) {
                if ($row->getInscription()) continue;
                // No fabricated email addresses or ambiguous name-only account matching.
                if (!$row->getEmail()) { ++$pending; continue; }
                if (isset($seenEmails[mb_strtolower($row->getEmail())])) { ++$pending; continue; }
                $seenEmails[mb_strtolower($row->getEmail())] = true;
                $user = $this->em->getRepository(Utilisateur::class)->createQueryBuilder('u')->where('LOWER(u.email) = :email')->setParameter('email', mb_strtolower($row->getEmail()))->getQuery()->getOneOrNullResult();
                $membership = $user ? $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $entite]) : null;
                if ($user && (!$membership || !$membership->isActive() || !$membership->hasRole(UtilisateurEntite::TENANT_STAGIAIRE)
                    || mb_strtolower(trim($user->getPrenom().' '.$user->getNom())) !== mb_strtolower(trim($row->getPrenom().' '.$row->getNom())))) {
                    ++$pending; continue;
                }
                $existing = $user ? $this->em->getRepository(Inscription::class)->findOneBy(['session' => $session, 'stagiaire' => $user]) : null;
                if ($existing && ($existing->getStatus() === StatusInscription::ANNULE || $existing->getEntite()?->getId() !== $entite->getId() || $existing->getEntreprise()?->getId() !== $convention->getEntreprise()?->getId())) { ++$pending; continue; }
                if (!$user) ++$newLearners;
                if (!$existing) ++$newEnrollments;
                $prepared[] = [$row, $user, $existing];
            }
            $active = $this->em->getRepository(Inscription::class)->createQueryBuilder('i')->select('COUNT(i.id)')->where('i.session = :session')->andWhere('i.status != :canceled')->setParameter('session', $session)->setParameter('canceled', StatusInscription::ANNULE)->getQuery()->getSingleScalarResult();
            if ((int) $active + $newEnrollments > $session->getCapacite()) throw new \DomainException('La capacité de la session est insuffisante.');
            if ($newLearners > 0) {
                // Every expected business refusal must happen before participant objects are modified.
                $this->billing->assertCanTransitionUtilisateurEntite($entite, [], UtilisateurEntite::STATUS_INVITED,
                    [UtilisateurEntite::TENANT_STAGIAIRE], UtilisateurEntite::STATUS_ACTIVE);
                $this->billing->assertCanAddApprenantAndConsume($entite, $newLearners);
            }
            $writingParticipants = true;
            foreach ($prepared as [$row, $user, $inscription]) {
                $newAccount = $user === null;
                if (!$user) {
                    $user = (new Utilisateur())->setEmail($row->getEmail())->setPrenom($row->getPrenom())->setNom($row->getNom())->setDateNaissance($row->getDateNaissance())
                        ->setEntite($entite)->setCreateur($actor)->setEntreprise($convention->getEntreprise())->setRoles(['ROLE_USER']);
                    $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(32))));
                    $membership = (new UtilisateurEntite())->setUtilisateur($user)->setEntite($entite)->setCreateur($actor)->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
                    $this->em->persist($user); $this->em->persist($membership);
                }
                if (!$inscription) {
                    $inscription = (new Inscription())->setSession($session)->setStagiaire($user)->setEntreprise($convention->getEntreprise())->setEntite($entite)->setCreateur($actor)
                        ->setModeFinancement($convention->getEntreprise() ? ModeFinancement::ENTREPRISE : ModeFinancement::INDIVIDUEL);
                    $session->addInscription($inscription); $this->em->persist($inscription);
                    $dossier = (new DossierInscription())->setInscription($inscription)->setEntite($entite)->setCreateur($actor);
                    $inscription->setDossier($dossier); $this->em->persist($dossier);
                }
                if ($newAccount) $inscription->setMeta(($inscription->getMeta() ?? []) + ['workflowNewAccount' => true]);
                $convention->addInscription($inscription);
                $convention->getDevis()?->addInscription($inscription);
                $row->setInscription($inscription); $row->touch();
            }
            // Keep the frozen signed PDF intact. Un-signed documents omit names now linked to an enrollment.
            if ($prepared && !$convention->isSigned()) {
                $importedNames = array_map(static fn(array $item): string => trim($item[0]->getPrenom().' '.$item[0]->getNom()), $prepared);
                $remaining = array_values(array_filter($convention->getParticipantsLibresListe(), static fn(string $name): bool => !in_array($name, $importedNames, true)));
                $convention->setParticipantsLibres(implode("\n", $remaining))->setPdfPath(null);
            }
            if ($prepared) {
                $options = $workflow->getOptions(); $options['portalRevision'] = (int) ($options['portalRevision'] ?? 0) + 1; $workflow->setOptions($options);
                $this->em->flush();
                $this->planner->synchronize($workflow);
            }
            $connection->commit();
            return ['imported' => count($prepared), 'pending' => $pending];
        } catch (\Throwable $error) {
            if ($connection->getTransactionNestingLevel() > $level) $connection->rollBack();
            if ($error instanceof \DomainException && !$writingParticipants && $this->em->isOpen()) {
                // BillingGuard may prepare a new usage row before rejecting its quota. Nothing was
                // flushed and existing usage is not incremented on rejection. Detach just that
                // new pending object, preserving the caller's managed workflow and task for retry.
                foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
                    if (!isset($existingInsertions[spl_object_id($entity)])) $this->em->detach($entity);
                }
            } else {
                // A database/runtime failure during writes may leave stale objects in memory.
                // Follow Doctrine's failed-transaction contract; never disguise it as validation.
                $this->em->close();
            }
            throw $error;
        }
    }
}
