<?php

declare(strict_types=1);

namespace App\Service\Preproduction;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Entity\Billing\{Plan, EntiteSubscription};
use Doctrine\ORM\EntityManagerInterface;

/** Initial fictional organization. Never used by ordinary deployments. */
final class InitialData
{
    public function create(EntityManagerInterface $em, string $email, string $passwordHash): void
    {
        $offers = [
            ['SOLO', 'Solo', 2900, 100, 1, 1],
            ['PRO', 'Pro', 5900, 300, 3, 5],
            ['ORGANISME', 'Organisme', 9900, 1000, 10, 0],
            ['EXCELLENCE_2026', 'Excellence', 15000, 0, 0, 0],
        ];
        foreach ($offers as $index => [$code, $name, $price, $learners, $admins, $trainers]) {
            $plan = (new Plan())->setCode($code)->setName($name)->setPriceMonthlyCents($price)
                ->setMaxApprenantsAn($learners)->setMaxUtilisateurs($admins)->setMaxFormateurs($trainers)
                ->setSeatLimits(['admins' => $admins, 'formateurs' => $trainers])->setOrdre($index + 1);
            if ($code === 'EXCELLENCE_2026') $plan->setSupportPrioritaire(true);
            $em->persist($plan);
        }
        $sandbox = (new Plan())->setCode('SANDBOX')->setName('Préproduction — tests fictifs')
            ->setPriceMonthlyCents(0)->setSeatLimits(['admins' => 0, 'formateurs' => 0]);
        $admin = (new Utilisateur())->setEmail($email)->setNom('Tests')->setPrenom('Administrateur')
            ->setPassword($passwordHash)->setIsVerified(true)->setRoles(['ROLE_SUPER_ADMIN']);
        $organization = (new Entite())->setNom('PRÉPRODUCTION — Organisme fictif')->setPublic(false)
            ->setCreateur($admin)->setCouleurPrincipal('#233342')->setCouleurSecondaire('#ffc107');
        $admin->setEntite($organization);
        $membership = (new UtilisateurEntite())->setEntite($organization)->setUtilisateur($admin)
            ->setCreateur($admin)->setRoles(['TENANT_ADMIN'])->setStatus('active');
        $subscription = (new EntiteSubscription())->setEntite($organization)->setPlan($sandbox)->setStatus('active');
        foreach ([$sandbox, $admin, $organization, $membership, $subscription] as $entity) $em->persist($entity);
        $em->flush();
    }
}
