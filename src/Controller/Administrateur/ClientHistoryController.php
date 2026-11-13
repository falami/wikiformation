<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Devis, Facture, Session, ConventionContrat};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class ClientHistoryController extends AbstractController
{
    #[Route('/administrateur/{entite}/echanges/{type}/{id}', name: 'app_administrateur_client_history', requirements: ['type'=>'facture|devis|convention|session', 'id'=>'\\d+'], methods: ['GET'])]
    #[Route('/administrateur/{entite}/client-interactions/{type}/{id}', name: 'app_administrateur_client_interactions', requirements: ['type'=>'facture|devis|convention|session', 'id'=>'\\d+'], methods: ['GET'])]
    public function history(Entite $entite, string $type, int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ADMIN, $entite);
        $this->denyAccessUnlessGranted(TenantPermission::UTILISATEUR_MANAGE, $entite);
        $class = match ($type) { 'facture'=>Facture::class, 'devis'=>Devis::class, 'session'=>Session::class, default=>ConventionContrat::class };
        $document = $em->getRepository($class)->find($id);
        if (!$document || $document->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if ($document instanceof Session) return $this->sessionContact($document, $entite, $em);
        $company = match ($type) { 'convention' => $document->getEntreprise(), default => $document->getEntrepriseDestinataire() };
        $user = match ($type) { 'convention' => $document->getStagiaire(), default => $document->getDestinataire() };
        $quote = $type === 'devis' ? $document : ($type === 'convention' ? $document->getDevis() : null);
        $prospect = $quote?->getProspect();
        $emails = array_values(array_unique(array_filter([$company?->getEmail(), $company?->getEmailFacturation(), $user?->getEmail(), $prospect?->getEmail()])));
        // Prefer the existing client workspace, using only contacts of this organisation.
        $contact = $user ?? $company?->getRepresentant() ?? $prospect?->getLinkedUser();
        $memberships = $em->getRepository(\App\Entity\UtilisateurEntite::class);
        if ($contact && !$memberships->findOneBy(['entite'=>$entite, 'utilisateur'=>$contact])) $contact = null;
        if (!$contact && $emails) {
            $matches = $em->getRepository(\App\Entity\Utilisateur::class)->createQueryBuilder('u')
                ->join('u.utilisateurEntites', 'membership')
                ->where('membership.entite = :tenant')->andWhere('u.email IN (:emails)')
                ->setParameter('tenant', $entite)->setParameter('emails', $emails)
                ->distinct()->setMaxResults(2)->getQuery()->getResult();
            if (count($matches) === 1) $contact = $matches[0];
        }
        if ($contact && $this->isGranted(TenantPermission::UTILISATEUR_MANAGE, $entite)) {
            $response = $this->redirectToRoute('app_administrateur_utilisateur_show', ['entite'=>$entite->getId(), 'id'=>$contact->getId(), '_fragment'=>'pane-histo']);
            $response->headers->set('Cache-Control', 'private, no-store');
            return $response;
        }
        $response = $this->render('administrateur/client_contacts.html.twig', [
            'entite'=>$entite, 'document'=>$document, 'type'=>$type,
            'contacts'=>$matches ?? [],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
    private function sessionContact(Session $session, Entite $entite, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::UTILISATEUR_MANAGE, $entite);
        $candidates = [];
        $emails = [];
        $add = static function ($user, $company = null) use (&$candidates, &$emails): void {
            $user ??= $company?->getRepresentant();
            if ($user) $candidates[$user->getId()] = $user;
            foreach ([$user?->getEmail(), $company?->getEmail(), $company?->getEmailFacturation()] as $email) {
                if ($email) $emails[] = mb_strtolower(trim($email));
            }
        };
        // Explicit document recipients are the commercial contacts, whereas
        // the attendees may be employees of the customer.
        foreach ($session->getConventionContrats() as $convention) {
            if ($convention->getEntite()?->getId() !== $entite->getId()) continue;
            $quote = $convention->getDevis();
            if ($quote && $quote->getEntite()?->getId() === $entite->getId()) {
                $add($quote->getDestinataire(), $quote->getEntrepriseDestinataire());
            }
            $add($convention->getEntreprise() ? null : $convention->getStagiaire(), $convention->getEntreprise());
        }
        foreach ($session->getInscriptions() as $registration) {
            if ($registration->getEntite()?->getId() !== $entite->getId()) continue;
            foreach ([...$registration->getDevis(), ...$registration->getFactures()] as $document) {
                if ($document->getEntite()?->getId() !== $entite->getId()) continue;
                $add($document->getDestinataire(), $document->getEntrepriseDestinataire());
            }
        }
        if (!$candidates && !$emails) {
            $add(null, $session->getEntrepriseCliente() ?? $session->getOrganismeFormation());
        }
        if (!$candidates && !$emails) {
            foreach ($session->getInscriptions() as $registration) {
                if ($registration->getEntite()?->getId() !== $entite->getId()) continue;
                $add($registration->getEntreprise() ? null : $registration->getStagiaire(), $registration->getEntreprise());
            }
        }
        $memberships = $em->getRepository(\App\Entity\UtilisateurEntite::class);
        $contacts = array_filter($candidates, static fn($user) => $memberships->findOneBy(['entite'=>$entite, 'utilisateur'=>$user]) !== null);
        if (!$contacts && $emails) {
            $contacts = $em->getRepository(\App\Entity\Utilisateur::class)->createQueryBuilder('u')
                ->join('u.utilisateurEntites', 'membership')
                ->where('membership.entite = :tenant')->andWhere('LOWER(TRIM(u.email)) IN (:emails)')
                ->setParameter('tenant', $entite)->setParameter('emails', array_values(array_unique($emails)))
                ->distinct()->getQuery()->getResult();
        }
        if (count($contacts) === 1) {
            $contact = reset($contacts);
            $response = $this->redirectToRoute('app_administrateur_utilisateur_show', ['entite'=>$entite->getId(), 'id'=>$contact->getId(), '_fragment'=>'pane-histo']);
        } else {
            $response = $this->render('administrateur/client_contacts.html.twig', ['entite'=>$entite, 'document'=>$session, 'type'=>'session', 'contacts'=>$contacts]);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
