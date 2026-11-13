<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, Devis, Facture, Session, ConventionContrat, EmailLog, ProspectInteraction};
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class ClientHistoryController extends AbstractController
{
    #[Route('/administrateur/{entite}/echanges/{type}/{id}', name: 'app_administrateur_client_history', requirements: ['type'=>'facture|devis|convention|session', 'id'=>'\\d+'], methods: ['GET'])]
    public function history(Entite $entite, string $type, int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::ADMIN, $entite);
        $class = match ($type) { 'facture'=>Facture::class, 'devis'=>Devis::class, 'session'=>Session::class, default=>ConventionContrat::class };
        $document = $em->getRepository($class)->find($id);
        if (!$document || $document->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $company = match ($type) { 'session' => $document->getEntrepriseCliente() ?? $document->getOrganismeFormation(), 'convention' => $document->getEntreprise(), default => $document->getEntrepriseDestinataire() };
        $user = match ($type) { 'session' => null, 'convention' => $document->getStagiaire(), default => $document->getDestinataire() };
        $sessionUsers = [];
        $sessionClients = [];
        $sessionEmails = [];
        if ($type === 'session' && !$company) {
            foreach ($document->getInscriptions() as $inscription) {
                if ($inscription->getEntite()?->getId() !== $entite->getId()) continue;
                $clientCompany = $inscription->getEntreprise();
                $clientKey = $clientCompany ? 'company-'.$clientCompany->getId() : 'user-'.$inscription->getStagiaire()?->getId();
                $sessionClients[$clientKey] = true;
                $client = $clientCompany ? $clientCompany->getRepresentant() : $inscription->getStagiaire();
                if ($client) $sessionUsers[$client->getId()] = $client;
                foreach ([$clientCompany?->getEmail(), $clientCompany?->getEmailFacturation(), $client?->getEmail()] as $email) {
                    if ($email) $sessionEmails[] = $email;
                }
            }
            if (count($sessionClients) === 1 && count($sessionUsers) === 1) $user = reset($sessionUsers);
        }
        $quote = $type === 'devis' ? $document : ($type === 'convention' ? $document->getDevis() : null);
        $prospect = $quote?->getProspect();
        $emails = array_values(array_unique(array_filter([$company?->getEmail(), $company?->getEmailFacturation(), $user?->getEmail(), $prospect?->getEmail(), ...$sessionEmails])));
        // Prefer the existing client workspace, using only contacts of this organisation.
        $contact = $company ? $company->getRepresentant() : $user;
        $memberships = $em->getRepository(\App\Entity\UtilisateurEntite::class);
        if ($contact && !$memberships->findOneBy(['entite'=>$entite, 'utilisateur'=>$contact])) $contact = null;
        if (!$contact && $emails && count($sessionClients) <= 1) {
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
        $events = [];
        foreach ([EmailLog::class, ProspectInteraction::class] as $entityClass) {
            $isMail = $entityClass === EmailLog::class;
            $qb = $em->getRepository($entityClass)->createQueryBuilder('h')->where('h.entite = :tenant')->setParameter('tenant', $entite);
            $conditions = [];
            if ($type === 'facture') { $conditions[] = 'h.facture = :doc'; $qb->setParameter('doc', $document); }
            if ($quote) { $conditions[] = 'h.devis = :quote'; $qb->setParameter('quote', $quote); }
            if ($prospect) { $conditions[] = 'h.prospect = :prospect'; $qb->setParameter('prospect', $prospect); }
            if ($sessionUsers) {
                $conditions[] = $isMail ? 'h.toUser IN (:sessionUsers)' : 'h.utilisateur IN (:sessionUsers)';
                $qb->setParameter('sessionUsers', array_values($sessionUsers));
            }
            if ($user) { $conditions[] = $isMail ? 'h.toUser = :client' : 'h.utilisateur = :client'; $qb->setParameter('client', $user); }
            if ($emails) {
                if ($isMail) $conditions[] = 'h.toEmail IN (:emails)';
                else { $qb->leftJoin('h.emailLog', 'mail'); $conditions[] = '(mail.entite = :tenant AND mail.toEmail IN (:emails))'; }
                $qb->setParameter('emails', $emails);
            }
            if (!$conditions) continue;
            $qb->andWhere('('.implode(' OR ', $conditions).')');
            foreach ($qb->getQuery()->getResult() as $entry) {
                // Mail-backed notes are already represented by the archived message.
                if (!$isMail && $entry->getEmailLog()) continue;
                $events[] = ['mail'=>$isMail, 'entry'=>$entry, 'date'=>$isMail ? $entry->getSentAt() : $entry->getOccurredAt()];
            }
        }
        usort($events, static fn(array $a, array $b) => $b['date'] <=> $a['date']);
        $page = max(1, $request->query->getInt('page', 1));
        $response = $this->render('administrateur/client_history.html.twig', ['entite'=>$entite, 'document'=>$document, 'type'=>$type, 'events'=>array_slice($events, ($page-1)*30, 30), 'page'=>$page, 'hasNext'=>count($events)>$page*30]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
