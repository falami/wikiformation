<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;
use App\Entity\{Entite, QualityRecord, AuditLog};
use App\Form\Administrateur\QualityRecordType;
use App\Form\Administrateur\QualityIdentityType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Form\FormError;
use App\Security\Permission\TenantPermission;
use App\Service\Quality\QualityReadiness;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/qualite', name: 'app_administrateur_quality_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::QUALITY_MANAGE, subject: 'entite')]
final class QualityController extends AbstractController
{
    #[Route('/identite', name: 'identity', methods: ['GET', 'POST'])]
    #[IsGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, subject: 'entite')]
    public function identity(Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        $values = [];
        foreach (QualityIdentityType::FIELDS as $key => $_) $values[$key] = $entite->{'get'.ucfirst($key)}();
        $form = $this->createForm(QualityIdentityType::class, $values);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            foreach (QualityIdentityType::FIELDS as $key => $_) $entite->{'set'.ucfirst($key)}($data[$key]);
            $event = (new AuditLog())->setEntite($entite)->setCreateur($this->getUser())->setActor($this->getUser())
                ->setCreatedAt(new \DateTimeImmutable())->setEvent('QUALITY_IDENTITY_SAVED')->setPayload(['before' => $values, 'after' => $data]);
            $em->persist($event); $em->flush();
            $this->addFlash('success', 'Identité de l’organisme enregistrée. Les prochains documents réutiliseront ces informations ; les pièces déjà signées sont conservées.');
            return $this->redirectToRoute('app_administrateur_quality_index', ['entite' => $entite->getId()]);
        }
        return $this->render('administrateur/quality/identity.html.twig', ['entite' => $entite, 'form' => $form->createView()], new Response('', 200, ['Cache-Control' => 'private, no-store']));
    }
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, QualityReadiness $quality): Response
    {
        $response = $this->render('administrateur/quality/index.html.twig', ['entite' => $entite, 'report' => $quality->inspect($entite)]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
    #[Route('/export.json', name: 'export', methods: ['GET'])]
    public function export(Entite $entite, QualityReadiness $quality): JsonResponse
    {
        return new JsonResponse($quality->inspect($entite), 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'attachment; filename="diagnostic-qualite-'.$entite->getId().'.json"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
    #[Route('/registre', name: 'records', methods: ['GET'])]
    public function records(Entite $entite, EntityManagerInterface $em): Response
    {
        return $this->render('administrateur/quality/records.html.twig', ['entite' => $entite,
            'records' => $em->getRepository(QualityRecord::class)->findBy(['entite' => $entite], ['updatedAt' => 'DESC']),
        ], new Response('', 200, ['Cache-Control' => 'private, no-store']));
    }

    #[Route('/registre/nouveau', name: 'record_new', methods: ['GET', 'POST'])]
    #[Route('/registre/{id}/modifier', name: 'record_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Entite $entite, Request $request, EntityManagerInterface $em, ?int $id = null): Response
    {
        $record = $id !== null ? $em->find(QualityRecord::class, $id) : (new QualityRecord())->setEntite($entite);
        if (!$record) throw $this->createNotFoundException();
        if ($record->getEntite()?->getId() !== $entite->getId()) throw $this->createAccessDeniedException();
        if ($id === null && $request->isMethod('GET')) {
            $kind = $request->query->getString('kind');
            if (in_array($kind, QualityRecord::KINDS, true)) $record->setKind($kind);
            $record->setOwner(mb_substr(trim($this->getUser()->getPrenom().' '.$this->getUser()->getNom()), 0, 180));
        }
        $statusCode = 200;
        $form = $this->createForm(QualityRecordType::class, $record, ['version' => $record->getVersion()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ((string) $form->get('_version')->getData() !== (string) $record->getVersion()) {
                $form->addError(new FormError('Cette fiche a été modifiée entre-temps. Rechargez la page avant d’enregistrer.'));
                $statusCode = 409;
            } else {
                $record->recordEdit($this->getUser());
                $em->persist($record);
                try {
                $em->wrapInTransaction(function () use ($em, $record, $entite): void {
                    $em->flush();
                    $event = (new AuditLog())->setEntite($entite)->setCreateur($this->getUser())->setActor($this->getUser())
                        ->setCreatedAt(new \DateTimeImmutable())->setEvent('QUALITY_RECORD_SAVED')->setPayload([
                            'recordId' => $record->getId(), 'version' => $record->getVersion(),
                            'kind' => $record->getKind(), 'title' => $record->getTitle(), 'status' => $record->getStatus(),
                            'occurredAt' => $record->getOccurredAt()?->format('Y-m-d'), 'owner' => $record->getOwner(),
                            'dueAt' => $record->getDueAt()?->format('Y-m-d'), 'description' => $record->getDescription(),
                            'actions' => $record->getActions(), 'evidence' => $record->getEvidence(), 'conclusion' => $record->getConclusion(),
                        ]);
                    $em->persist($event);
                });
                $this->addFlash('success', 'Fiche qualité enregistrée et version conservée dans le journal.');
                return $this->redirectToRoute('app_administrateur_quality_records', ['entite' => $entite->getId()]);
                } catch (OptimisticLockException) {
                    $form->addError(new FormError('Cette fiche a été modifiée pendant votre enregistrement. Rechargez la page pour consulter la dernière version.'));
                    $statusCode = 409;
                }
            }
        }
        $history = [];
        if ($record->getId() && $em->isOpen()) {
            // DBAL JSON predicates differ between SQLite tests and MySQL; exact record identity is filtered here.
            foreach ($em->getRepository(AuditLog::class)->findBy(['entite' => $entite, 'event' => 'QUALITY_RECORD_SAVED'], ['id' => 'DESC']) as $event) {
                if (($event->getPayload()['recordId'] ?? null) === $record->getId()) $history[] = $event;
            }
        }
        return $this->render('administrateur/quality/form.html.twig', ['entite' => $entite, 'record' => $record, 'form' => $form->createView(), 'history' => $history], new Response('', $statusCode, ['Cache-Control' => 'private, no-store']));
    }

}
