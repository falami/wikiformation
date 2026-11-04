<?php

namespace App\Controller\Administrateur;

use App\Entity\{Entite, Prospect, ProspectInteraction};
use App\Enum\{ProspectStatus, InteractionChannel};
use App\Repository\ProspectRepository;
use App\Security\Permission\TenantPermission;
use App\Service\Prospect\ProspectFollowUp;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/prospection', name: 'app_administrateur_prospect_', requirements: ['entite' => '\\d+'])]
#[IsGranted(TenantPermission::PROSPECT_MANAGE, subject: 'entite')]
final class ProspectWorkspaceController extends AbstractController
{
    #[Route('/pilotage', name: 'workspace', methods: ['GET'], priority: 10)]
    public function workspace(Entite $entite, Request $request, ProspectRepository $repository, EntityManagerInterface $em): Response
    {
        $q = mb_substr(trim($request->query->getString('q')), 0, 180);
        $view = $request->query->getString('view', 'priorities');
        if (!in_array($view, ['priorities', 'pipeline', 'contacts'], true)) $view = 'priorities';
        $filter = $request->query->getString('filter', 'all');
        $status = ProspectStatus::tryFrom($request->query->getString('status'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $end = $now->setTime(23, 59, 59);
        // Scalar subqueries avoid loading email bodies and avoid multiplying rows through joins.
        $sent = '(SELECT MAX(el.sentAt) FROM App\\Entity\\EmailLog el WHERE el.prospect = p AND el.entite = :tenant AND el.status = :sentStatus)';
        $reply = '(SELECT MAX(ri.occurredAt) FROM App\\Entity\\ProspectInteraction ri WHERE ri.prospect = p AND ri.entite = :tenant AND ri.actorProspect = p AND ri.channel = :emailChannel)';
        $base = $repository->createQueryBuilder('p')->where('p.entite = :tenant')->setParameter('tenant', $entite);
        $open = 'p.isActive = true AND p.status NOT IN (:closed)';
        $closed = [ProspectStatus::WON, ProspectStatus::LOST, ProspectStatus::CONVERTED];
        $stats = (clone $base)->select('COUNT(p.id) AS total')
            ->addSelect("SUM(CASE WHEN $open THEN 1 ELSE 0 END) AS active")
            ->addSelect("SUM(CASE WHEN $open AND p.nextActionAt <= :end THEN 1 ELSE 0 END) AS due")
            ->addSelect("SUM(CASE WHEN $open AND p.nextActionAt IS NULL THEN 1 ELSE 0 END) AS unplanned")
            ->setParameter('closed', $closed)->setParameter('end', $end)->getQuery()->getSingleResult();
        $qb = (clone $base)->select('p')->addSelect("$sent AS lastSent", "$reply AS lastReply")
            ->setParameter('sentStatus', 'SENT')->setParameter('emailChannel', InteractionChannel::EMAIL);
        if ($q !== '') $qb->andWhere('(p.nom LIKE :search OR p.prenom LIKE :search OR p.societe LIKE :search OR p.email LIKE :search OR p.poste LIKE :search)')->setParameter('search', '%'.$q.'%');
        if ($status) $qb->andWhere('p.status = :status')->setParameter('status', $status);
        if ($view === 'priorities' || $filter !== 'all') $qb->andWhere($open)->setParameter('closed', $closed);
        if ($view === 'pipeline' && $filter === 'all') $qb->andWhere('p.isActive = true AND p.status != :converted')->setParameter('converted', ProspectStatus::CONVERTED);
        if ($filter === 'due') $qb->andWhere('p.nextActionAt <= :end')->setParameter('end', $end);
        if ($filter === 'unplanned') $qb->andWhere('p.nextActionAt IS NULL');
        if ($filter === 'waiting') {
            $qb->andWhere('EXISTS (SELECT waitingMail.id FROM App\\Entity\\EmailLog waitingMail WHERE waitingMail.prospect = p AND waitingMail.entite = :tenant AND waitingMail.status = :sentStatus AND NOT EXISTS (SELECT response.id FROM App\\Entity\\ProspectInteraction response WHERE response.prospect = p AND response.entite = :tenant AND response.actorProspect = p AND response.channel = :emailChannel AND response.occurredAt >= waitingMail.sentAt))');
        }
        if ($filter === 'rh') $qb->andWhere('(LOWER(p.poste) LIKE :rh OR LOWER(p.poste) LIKE :human)')->setParameter('rh', '%rh%')->setParameter('human', '%ressources humaines%');
        $page = max(1, $request->query->getInt('page', 1));
        $qb->addSelect('CASE WHEN p.nextActionAt IS NULL THEN 1 ELSE 0 END AS HIDDEN unscheduled')->orderBy('unscheduled', 'ASC')->addOrderBy('p.nextActionAt', 'ASC')->addOrderBy('p.score', 'DESC')->addOrderBy('p.id', 'DESC');
        $results = $qb->setFirstResult(($page - 1) * 60)->setMaxResults(61)->getQuery()->getResult();
        $hasNext = count($results) > 60;
        $rows = [];
        foreach (array_slice($results, 0, 60) as $result) {
            $p = $result[0];
            $lastSent = $result['lastSent'] ? new \DateTimeImmutable($result['lastSent']) : null;
            $lastReply = $result['lastReply'] ? new \DateTimeImmutable($result['lastReply']) : null;
            $rows[] = ['p' => $p, 'waiting' => ProspectFollowUp::waiting($lastSent, $lastReply), 'lastSent' => $lastSent, 'lastReply' => $lastReply, 'due' => ProspectFollowUp::isOpen($p) && $p->getNextActionAt() && $p->getNextActionAt() <= $end];
        }
        return $this->render('administrateur/prospects/workspace.html.twig', compact('entite', 'rows', 'stats', 'view', 'filter', 'q', 'status', 'page', 'hasNext') + ['statuses' => ProspectStatus::cases()]);
    }

    #[Route('/{prospect}/suivi', name: 'followup', methods: ['POST'], requirements: ['prospect' => '\\d+'])]
    public function followup(Entite $entite, Prospect $prospect, Request $request, EntityManagerInterface $em): Response
    {
        if ($prospect->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('prospect_followup_'.$prospect->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException('La page a expiré.');
        $action = $request->request->getString('action');
        $interaction = (new ProspectInteraction())->setEntite($entite)->setProspect($prospect)->setCreateur($this->getUser())->setActor($this->getUser())->setChannel(InteractionChannel::OTHER);
        try {
            $date = ProspectFollowUp::date($request->request->getString('date'));
            switch ($action) {
                case 'schedule':
                    if (!$date || !ProspectFollowUp::isOpen($prospect)) throw new \InvalidArgumentException('Choisissez une date pour un prospect actif.');
                    $prospect->setNextActionAt($date);
                    $interaction->setTitle('Relance planifiée au '.$date->format('d/m/Y'));
                    break;
                case 'reply':
                    $interaction->setActor(null)->setActorProspect($prospect)->setChannel(InteractionChannel::EMAIL)->setTitle('Réponse reçue — enregistrée manuellement');
                    $prospect->setNextActionAt($date);
                    if ($prospect->getStatus() === ProspectStatus::NEW) $prospect->setStatus(ProspectStatus::CONTACTED);
                    break;
                case 'status':
                    $status = ProspectStatus::tryFrom($request->request->getString('status'));
                    if (!$status || $status === ProspectStatus::CONVERTED || $prospect->getStatus() === ProspectStatus::CONVERTED) throw new \InvalidArgumentException('Utilisez la fiche pour convertir ce prospect.');
                    $interaction->setTitle('Étape : '.$prospect->getStatus()->label().' → '.$status->label());
                    $prospect->setStatus($status);
                    if (!ProspectFollowUp::isOpen($prospect)) $prospect->setNextActionAt(null);
                    break;
                case 'note':
                    $interaction->setTitle('Note de suivi');
                    if (trim($request->request->getString('note')) === '') throw new \InvalidArgumentException('Saisissez une note.');
                    break;
                default: throw new \InvalidArgumentException('Action inconnue.');
            }
            $interaction->setContent(mb_substr(trim($request->request->getString('note')), 0, 10000) ?: null);
            $prospect->touch();
            $em->persist($interaction);
            $em->flush();
            if ($request->isXmlHttpRequest()) return $this->json(['ok' => true]);
            $this->addFlash('success', 'Suivi de '.$prospect->getFullName().' enregistré.');
        } catch (\InvalidArgumentException $e) {
            if ($request->isXmlHttpRequest()) return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
            $this->addFlash('danger', $e->getMessage());
        }
        return $this->redirectToRoute('app_administrateur_prospect_workspace', ['entite' => $entite->getId()]);
    }
}
