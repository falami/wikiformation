<?php

namespace App\Service\Prospect;

use App\Entity\{Entite, Prospect, Utilisateur, Entreprise, Devis, UtilisateurEntite, EmailLog};
use App\Entity\ProspectInteraction;
use App\Enum\ProspectStatus;
use Doctrine\ORM\EntityManagerInterface as EM;
use App\Service\Billing\BillingGuard;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProspectConverter
{
  public function __construct(
      private EM $em,
      private UserPasswordHasherInterface $passwordHasher,
      private BillingGuard $billingGuard,
  ) {}

  public function convert(Entite $entite, Prospect $p, Utilisateur $currentUser): ProspectConversionResult
  {
    if ($p->getEntite()?->getId() !== $entite->getId()) {
      throw new \RuntimeException('Prospect/Entite mismatch');
    }

    // ✅ Déjà converti ? => on ne casse rien
    if (!$p->isActive() || $p->getStatus() === ProspectStatus::CONVERTED) {
      return new ProspectConversionResult($p->getLinkedUser(), $p->getLinkedEntreprise());
    }

    return $this->em->wrapInTransaction(function () use ($entite, $p, $currentUser) {

      // ✅ Avec ton nouveau form, la vérité c'est linkedEntreprise
      $entreprise = $p->getLinkedEntreprise(); // peut être null
      $user = null;

      /**
       * =========================
       * CAS 1 : une entreprise est déjà sélectionnée (linkedEntreprise)
       * =========================
       */
      if ($entreprise instanceof Entreprise) {

        // ✅ sécurité multi-tenant
        if ($entreprise->getEntite()?->getId() !== $entite->getId()) {
          throw new \RuntimeException('Entreprise/Entite mismatch');
        }

        // ✅ Si email présent : on crée/associe le contact Utilisateur + on backfill
        if ($p->getEmail()) {
          $user = $this->findOrCreateUserFromProspect($entite, $p, $currentUser);

          if ($user->getEntreprise() === null) {
            $user->setEntreprise($entreprise);
          }

          $this->findOrCreateLinkEntite($entite, $user, $currentUser);

          $this->backfillEmailLogsToUser($entite, $p, $user);
          $this->backfillInteractionsToUser($entite, $p, $user);
        }

        // Rattache les devis
        foreach ($p->getDevis() as $devis) {
          /** @var Devis $devis */
          if (method_exists($devis, 'setEntrepriseDestinataire')) {
            $devis->setEntrepriseDestinataire($entreprise);
          }

          $devis->setDestinataire($user ?: null);
          $devis->setProspect(null);
        }

        // Lien de conversion (entreprise déjà dans linkedEntreprise)
        if ($user) {
          $p->setLinkedUser($user);
        }

        $p->markConverted();
        $this->em->flush();

        return new ProspectConversionResult($user, $entreprise);
      }

      /**
       * =========================
       * CAS 2 : pas d'entreprise sélectionnée
       * => chez toi, le bouton est désactivé si pas d'email,
       *    donc ici : user obligatoire.
       * =========================
       */
      $user = $this->findOrCreateUserFromProspect($entite, $p, $currentUser);

      // Lien prospect -> user
      $p->setLinkedUser($user);

      // ✅ backfill emails + interactions
      $this->backfillEmailLogsToUser($entite, $p, $user);
      $this->backfillInteractionsToUser($entite, $p, $user);

      // ✅ lien utilisateur <-> entite
      $this->findOrCreateLinkEntite($entite, $user, $currentUser);

      // Rattache devis au user
      foreach ($p->getDevis() as $devis) {
        /** @var Devis $devis */
        $devis->setDestinataire($user);
        $devis->setProspect(null);

        if (method_exists($devis, 'setEntrepriseDestinataire')) {
          $devis->setEntrepriseDestinataire(null);
        }
      }

      $p->markConverted();
      $this->em->flush();

      return new ProspectConversionResult($user, null);
    });
  }

  private function findOrCreateUserFromProspect(Entite $entite, Prospect $p, Utilisateur $currentUser): Utilisateur
  {
    $email = $p->getEmail();
    if (!$email) {
      throw new \RuntimeException('Impossible de convertir : email manquant.');
    }

    /** @var Utilisateur|null $u */
    $u = $this->em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);

    if ($u) {
      // Optionnel : enrichir si champs vides
      if (!$u->getTelephone() && $p->getTelephone()) $u->setTelephone($p->getTelephone());
      if (!$u->getPrenom() && $p->getPrenom())     $u->setPrenom($p->getPrenom());
      if (!$u->getNom() && $p->getNom())           $u->setNom($p->getNom());
      return $u;
    }

    $u = new Utilisateur();
    $u->setEmail($email);
    $u->setPrenom($p->getPrenom());
    $u->setNom($p->getNom());
    $u->setTelephone($p->getTelephone());
    $u->setCivilite($p->getCivilite());
    $u->setAdresse($p->getAdresse());
    $u->setComplement($p->getComplement());
    $u->setCodePostal($p->getCodePostal());
    $u->setVille($p->getVille());
    $u->setPays($p->getPays());
    $u->setRegion($p->getRegion());
    $u->setDepartement($p->getDepartement());

    // ✅ Corrige la casse des setters
    $u->setRoles(['ROLE_USER']);
    $u->setCreateur($currentUser);

    // Si tu rattaches un user à une entité “référente”
    $u->setEntite($entite);

    // Password random -> à remplacer par ton flow d’invitation/reset
    $plain = bin2hex(random_bytes(16));
    $u->setPassword($this->passwordHasher->hashPassword($u, $plain));
    $u->setIsVerified(false);

    $this->em->persist($u);

    return $u;
  }

  private function findOrCreateLinkEntite(Entite $entite, Utilisateur $user, Utilisateur $currentUser): UtilisateurEntite
  {
      /** @var UtilisateurEntite|null $ue */
      $ue = $this->em->getRepository(UtilisateurEntite::class)->findOneBy([
          'utilisateur' => $user,
          'entite' => $entite,
      ]);

      if ($ue) {
          return $ue;
      }

      $futureRoles = [UtilisateurEntite::TENANT_STAGIAIRE];
      $futureStatus = UtilisateurEntite::STATUS_ACTIVE;

      $this->billingGuard->assertCanTransitionUtilisateurEntite(
          entite: $entite,
          currentRoles: [],
          currentStatus: UtilisateurEntite::STATUS_INVITED,
          futureRoles: $futureRoles,
          futureStatus: $futureStatus,
          excludeUtilisateurEntiteId: null,
      );

      $ue = new UtilisateurEntite();
      $ue->setEntite($entite);
      $ue->setUtilisateur($user);
      $ue->setCreateur($currentUser);
      $ue->setCouleur(sprintf('#%06X', mt_rand(0, 0xFFFFFF)));
      $ue->setRoles($futureRoles);
      $ue->setStatus($futureStatus);

      $this->em->persist($ue);

      return $ue;
  }

  private function backfillEmailLogsToUser(Entite $entite, Prospect $p, Utilisateur $user): void
  {
    $this->em->createQueryBuilder()
      ->update(EmailLog::class, 'l')
      ->set('l.toUser', ':u')
      ->where('l.entite = :e')
      ->andWhere('l.prospect = :p')
      ->andWhere('l.toUser IS NULL')
      ->setParameter('u', $user)
      ->setParameter('e', $entite)
      ->setParameter('p', $p)
      ->getQuery()
      ->execute();
  }

  private function backfillInteractionsToUser(Entite $entite, Prospect $p, Utilisateur $user): void
  {
    $this->em->createQueryBuilder()
      ->update(ProspectInteraction::class, 'i')
      ->set('i.utilisateur', ':u')
      ->where('i.prospect = :p')
      ->andWhere('i.utilisateur IS NULL')
      ->setParameter('u', $user)
      ->setParameter('p', $p)
      ->getQuery()
      ->execute();
  }
}
