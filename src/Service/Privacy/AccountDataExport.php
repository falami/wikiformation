<?php

declare(strict_types=1);

namespace App\Service\Privacy;

use App\Entity\{Utilisateur, UtilisateurEntite, Inscription};
use Doctrine\ORM\EntityManagerInterface;

/** Explicit allowlist: never serialize entities, arbitrary JSON, credentials or third-party dossiers. */
final class AccountDataExport
{
    public function __construct(private EntityManagerInterface $em) {}

    public function export(Utilisateur $user): array
    {
        $data = [
            'version' => 1,
            'exportedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'scope' => 'Informations de compte, rattachements aux organismes et résumé de vos inscriptions uniquement. Pour toutes vos données (documents, évaluations, historique, etc.), déposez une demande d’accès. Les achats et parcours e-learning disposent également de leur export dédié.',
            'account' => [
                'id' => $user->getId(), 'email' => $user->getEmail(), 'firstName' => $user->getPrenom(), 'lastName' => $user->getNom(),
                'phone' => $user->getTelephone(), 'dateOfBirth' => $user->getDateNaissance()?->format('Y-m-d'),
                'address' => $user->getAdresse(), 'addressComplement' => $user->getComplement(), 'postalCode' => $user->getCodePostal(), 'city' => $user->getVille(), 'country' => $user->getPays(),
                'createdAt' => $user->getDateCreation()?->format(DATE_ATOM), 'newsletterPreference' => $user->isNewsletter(),
            ],
            'memberships' => [], 'enrolments' => [],
        ];
        foreach ($this->em->getRepository(UtilisateurEntite::class)->findBy(['utilisateur' => $user]) as $link) {
            $data['memberships'][] = ['organization' => $link->getEntite()?->getNom(), 'roles' => $link->getRoles(), 'function' => $link->getFonction(), 'status' => $link->getStatus(), 'createdAt' => $link->getDateCreation()?->format(DATE_ATOM)];
        }
        foreach ($this->em->getRepository(Inscription::class)->findBy(['stagiaire' => $user]) as $inscription) {
            $session = $inscription->getSession();
            $data['enrolments'][] = ['reference' => $inscription->getId(), 'organization' => $inscription->getEntite()?->getNom(), 'session' => $session?->getCode(), 'training' => $session?->getFormation()?->getTitre(), 'status' => $inscription->getStatus()->value, 'createdAt' => $inscription->getDateCreation()?->format(DATE_ATOM), 'attendanceRate' => $inscription->getTauxAssiduite(), 'passed' => $inscription->isReussi()];
        }
        return $data;
    }
}
