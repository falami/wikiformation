<?php

namespace App\Service\Entreprise;

use App\Entity\{Entreprise, Entite, Utilisateur, UtilisateurEntite};
use App\Service\Billing\BillingGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntrepriseClientEditor
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private BillingGuard $billing,
    ) {}

    /** Never reveal an account belonging only to another organisation. */
    public function lookup(string $email, Entite $tenant): ?Utilisateur
    {
        $matches = $this->em->getRepository(Utilisateur::class)->findByCanonicalEmail(['email' => $email]);
        if (count($matches) !== 1) return null;
        return $this->membership($matches[0], $tenant) ? $matches[0] : null;
    }

    /** Prepare without persisting; the controller saves everything in one flush after all validation. */
    public function prepareRepresentative(Entreprise $company, string $mode, array $data, Utilisateur $actor): ?UtilisateurEntite
    {
        $tenant = $company->getEntite();
        if ($mode === 'later') {
            $company->setRepresentant(null);
            return null;
        }
        $account = $company->getRepresentant();
        if ($mode === 'new') {
            $email = Utilisateur::normalizeEmail($data['email'] ?? '');
            if (!$email) throw new \InvalidArgumentException('Renseignez l’adresse e-mail du représentant.');
            $matches = $this->em->getRepository(Utilisateur::class)->findByCanonicalEmail(['email' => $email]);
            if ($matches) {
                $account = $this->lookup($email, $tenant);
                if (!$account) throw new \InvalidArgumentException('Ce compte ne peut pas être associé depuis cette page. Utilisez la gestion des utilisateurs pour vérifier son rattachement à votre organisme.');
                if (empty($data['reuse'])) throw new \InvalidArgumentException('Cette adresse correspond à un utilisateur existant. Confirmez l’utilisation de ce compte.');
            } else {
                if (!trim($data['prenom'] ?? '') || !trim($data['nom'] ?? '')) {
                    throw new \InvalidArgumentException('Renseignez le prénom et le nom du nouveau représentant.');
                }
                $account = (new Utilisateur())->setEmail($email)->setEntite($tenant)->setCreateur($actor)
                    ->setNom(trim($data['nom']))->setPrenom(trim($data['prenom']))
                    ->setCivilite($data['civilite'] ?? null)->setTelephone($data['telephone'] ?? null);
                $account->setPassword($this->hasher->hashPassword($account, bin2hex(random_bytes(32))));
            }
        } elseif ($mode !== 'existing') {
            throw new \InvalidArgumentException('Choisissez comment ajouter votre représentant.');
        }
        if (!$account) throw new \InvalidArgumentException('Sélectionnez un représentant ou choisissez « Plus tard ».');
        $membership = $account->getId() ? $this->membership($account, $tenant) : null;
        if ($account->getId() && !$membership) throw new \InvalidArgumentException('Cet utilisateur ne fait pas partie de votre organisme.');
        $roles = array_values(array_unique([...($membership?->getRoles() ?? []), UtilisateurEntite::TENANT_ENTREPRISE]));
        $status = $membership?->getStatus() ?? UtilisateurEntite::STATUS_ACTIVE;
        $this->billing->assertCanTransitionUtilisateurEntite(
            entite: $tenant, currentRoles: $membership?->getRoles() ?? [],
            currentStatus: $membership?->getStatus() ?? UtilisateurEntite::STATUS_INVITED,
            futureRoles: $roles, futureStatus: $status, excludeUtilisateurEntiteId: $membership?->getId(),
        );
        // A legacy employer link also grants portal access once the company role is added.
        // Preserve that employer as a trainee association instead of granting it a new mandate.
        $employer = $account->getEntreprise();
        if (!$membership?->hasRole(UtilisateurEntite::TENANT_ENTREPRISE)
            && $employer?->getEntite()?->getId() === $tenant->getId()) {
            $account->addEntreprisesAssociee($employer);
            $account->setEntreprise(null);
        }
        $membership ??= (new UtilisateurEntite())->setUtilisateur($account)->setEntite($tenant)->setCreateur($actor);
        $membership->setRoles($roles)->setStatus($status);
        $company->setRepresentant($account);
        return $membership;
    }

    /** Trainee associations never grant company portal access or overwrite another employer. */
    public function syncUsers(Entreprise $company, iterable $selected): void
    {
        $selected = is_array($selected) ? $selected : iterator_to_array($selected);
        $previous = $company->getId() ? $this->em->createQueryBuilder()
            ->select('DISTINCT u')->from(Utilisateur::class, 'u')
            ->innerJoin('u.utilisateurEntites', 'ue')->leftJoin('u.entreprisesAssociees', 'company')
            ->where('ue.entite = :tenant')->andWhere('company = :company OR u.entreprise = :company')
            ->setParameter('tenant', $company->getEntite())->setParameter('company', $company)
            ->getQuery()->getResult() : [];
        foreach ($previous as $user) {
            if (!in_array($user, $selected, true)) {
                $user->removeEntreprisesAssociee($company);
                if ($user->getEntreprise() === $company) $user->setEntreprise(null);
            }
        }
        foreach ($selected as $user) {
            if (!$this->membership($user, $company->getEntite())) throw new \InvalidArgumentException('Utilisateur extérieur à cet organisme.');
            $user->addEntreprisesAssociee($company);
        }
    }

    private function membership(Utilisateur $user, Entite $tenant): ?UtilisateurEntite
    {
        return $this->em->getRepository(UtilisateurEntite::class)->findOneBy(['utilisateur' => $user, 'entite' => $tenant]);
    }
}
