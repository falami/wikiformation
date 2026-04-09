<?php

declare(strict_types=1);

namespace App\Controller\OrganismeFormation;

use App\Entity\{
    Entite,
    Utilisateur,
    Entreprise,
    Session,
    SessionPiece
};
use App\Form\Administrateur\SessionPieceUploadType;
use App\Security\Permission\TenantPermission;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, JsonResponse, Response, ResponseHeaderBag};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/of/{entite}/documents', name: 'app_of_documents_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::OF_DOCUMENTS_MANAGE, subject: 'entite')]
final class DocumentsOrganismeFormationController extends AbstractController
{
    public function __construct(
        private FileUploader $uploader,
    ) {
    }

    private function getOrganismeFormationUserOrFail(): Entreprise
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $of = $user->getEntreprise();
        if (!$of) {
            throw $this->createAccessDeniedException('Aucun organisme de formation associé à ce compte.');
        }

        return $of;
    }

    private function dtParams(Request $request): array
    {
        $draw   = $request->request->getInt('draw', 1);
        $start  = max(0, $request->request->getInt('start', 0));
        $length = $request->request->getInt('length', 10);
        if ($length <= 0) {
            $length = 10;
        }

        $searchV = trim((string) (($request->request->all('search')['value'] ?? '') ?: ''));
        $order   = $request->request->all('order') ?? [];

        $orderColIdx = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
        $orderDir    = (isset($order[0]['dir']) && strtolower((string) $order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';

        return [$draw, $start, $length, $searchV, $orderColIdx, $orderDir];
    }

    private function resolveSessionPiecePath(SessionPiece $piece): string
    {
        $filename = basename((string) $piece->getFilename());

        return rtrim((string) $this->getParameter('session_piece_dir'), '/\\')
            . DIRECTORY_SEPARATOR
            . $filename;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        return $this->render('of/documents/index.html.twig', [
            'entite' => $entite,
            'organismeFormation' => $of,
        ]);
    }

    #[Route('/session-pieces/ajax', name: 'session_pieces_ajax', methods: ['POST'])]
    public function sessionPiecesAjax(Entite $entite, Request $request, EM $em): JsonResponse
    {
        $of = $this->getOrganismeFormationUserOrFail();
        [$draw, $start, $length, $searchV, $orderColIdx, $orderDir] = $this->dtParams($request);

        $typeFilter = (string) $request->request->get('typeFilter', 'all');
        $validFilter = (string) $request->request->get('validFilter', 'all');
        $sessionFilter = $request->request->getInt('sessionId', 0);

        $map = [
            0 => 'sp.uploadedAt',
            1 => 's.code',
            2 => 'sp.type',
            3 => 'sp.valide',
        ];

        $qb = $em->getRepository(SessionPiece::class)->createQueryBuilder('sp')
            ->innerJoin('sp.session', 's')->addSelect('s')
            ->leftJoin('s.formation', 'f')->addSelect('f')
            ->andWhere('sp.entite = :entite')->setParameter('entite', $entite)
            ->andWhere('s.organismeFormation = :of')->setParameter('of', $of);

        if ($sessionFilter > 0) {
            $qb->andWhere('s.id = :sid')->setParameter('sid', $sessionFilter);
        }

        $recordsTotal = (int) (clone $qb)
            ->select('COUNT(DISTINCT sp.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        if ($searchV !== '') {
            $qb->andWhere('(sp.filename LIKE :s OR s.code LIKE :s)')
                ->setParameter('s', '%' . $searchV . '%');
        }

        if ($typeFilter !== 'all') {
            $qb->andWhere('sp.type = :typeFilter')->setParameter('typeFilter', $typeFilter);
        }

        if ($validFilter === 'valid') {
            $qb->andWhere('sp.valide = true');
        } elseif ($validFilter === 'pending') {
            $qb->andWhere('sp.valide = false');
        }

        $recordsFiltered = (int) (clone $qb)
            ->select('COUNT(DISTINCT sp.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $orderBy = $map[$orderColIdx] ?? 'sp.uploadedAt';

        /** @var SessionPiece[] $rows */
        $rows = $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()
            ->getResult();

        $data = [];
        foreach ($rows as $sp) {
            $session = $sp->getSession();
            $sessionLabel = $session?->getCode() ?: ('Session #' . $session?->getId());

            $type = $sp->getType();
            $typeLabel = $type
                ? (method_exists($type, 'label') ? $type->label() : (method_exists($type, 'value') ? $type->value : '—'))
                : '—';

            $viewUrl = $this->generateUrl('app_of_documents_session_piece_view', [
                'entite' => $entite->getId(),
                'id' => $sp->getId(),
            ]);

            $downloadUrl = $this->generateUrl('app_of_documents_session_piece_download', [
                'entite' => $entite->getId(),
                'id' => $sp->getId(),
            ]);

            $data[] = [
                'date' => $sp->getUploadedAt()?->format('d/m/Y H:i') ?? '—',
                'session' => htmlspecialchars($sessionLabel, ENT_QUOTES),
                'formation' => htmlspecialchars($session?->getFormation()?->getTitre() ?? $session?->getFormationIntituleLibre() ?? '—', ENT_QUOTES),
                'type' => '<span class="badge bg-light text-dark">' . htmlspecialchars((string) $typeLabel, ENT_QUOTES) . '</span>',
                'fichier' => htmlspecialchars($sp->getFilename(), ENT_QUOTES),
                'statut' => $sp->isValide()
                    ? '<span class="badge bg-success-subtle text-success">Validé</span>'
                    : '<span class="badge bg-warning-subtle text-warning">En attente</span>',
                'actions' => sprintf(
                    '<div class="d-flex gap-2 justify-content-end flex-wrap">
                        <a class="btn btn-sm btn-outline-primary" href="%s" target="_blank">
                            <i class="bi bi-eye"></i> Voir
                        </a>
                        <a class="btn btn-sm btn-outline-secondary" href="%s">
                            <i class="bi bi-download"></i> Télécharger
                        </a>
                    </div>',
                    htmlspecialchars($viewUrl, ENT_QUOTES),
                    htmlspecialchars($downloadUrl, ENT_QUOTES)
                ),
            ];
        }

        return new JsonResponse([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Entite $entite, EM $em, Request $request): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        $piece = new SessionPiece();
        $form = $this->createForm(SessionPieceUploadType::class, $piece);
        $form->handleRequest($request);

        $sessionId = $request->request->getInt('session_id', 0);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var Utilisateur $user */
            $user = $this->getUser();

            /** @var Session|null $session */
            $session = null;
            if ($sessionId > 0) {
                $session = $em->getRepository(Session::class)->find($sessionId);
            }

            if (!$session) {
                $this->addFlash('danger', 'Aucune session valide n’a été fournie pour ce document.');

                return $this->redirectToRoute('app_of_dashboard', [
                    'entite' => $entite->getId(),
                ]);
            }

            if ($session->getEntite()?->getId() !== $entite->getId()) {
                throw $this->createAccessDeniedException();
            }

            if ($session->getOrganismeFormation()?->getId() !== $of->getId()) {
                throw $this->createAccessDeniedException();
            }

            $file = $form->get('file')->getData();

            if ($file) {
                $stored = $this->uploader->upload($file, 'session_pieces');

                $piece->setSession($session);
                $piece->setEntite($entite);
                $piece->setCreateur($user);
                $piece->setFilename($stored['filename']);
                $piece->setMimeType($stored['mimeType'] ?? $file->getClientMimeType());
                $piece->setUploadedAt(new \DateTimeImmutable());
                $piece->setDateCreation(new \DateTimeImmutable());

                $em->persist($piece);
                $em->flush();

                $this->addFlash('success', 'Document OF déposé avec succès.');
            }
        }

        return $this->redirectToRoute('app_of_dashboard', [
            'entite' => $entite->getId(),
        ]);
    }

    #[Route('/session-piece/{id}/view', name: 'session_piece_view', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sessionPieceView(Entite $entite, SessionPiece $piece): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        if ($piece->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        $session = $piece->getSession();
        if (!$session || $session->getOrganismeFormation()?->getId() !== $of->getId()) {
            throw $this->createAccessDeniedException();
        }

        $filename = basename((string) $piece->getFilename());
        $path = $this->resolveSessionPiecePath($piece);

        if (!is_file($path)) {
            throw $this->createNotFoundException(sprintf('Fichier introuvable : %s', $filename));
        }

        return $this->file(
            $path,
            $filename,
            ResponseHeaderBag::DISPOSITION_INLINE,
            [
                'Content-Type' => $piece->getMimeType() ?: 'application/octet-stream',
            ]
        );
    }

    #[Route('/session-piece/{id}/download', name: 'session_piece_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function sessionPieceDownload(Entite $entite, SessionPiece $piece): Response
    {
        $of = $this->getOrganismeFormationUserOrFail();

        if ($piece->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        $session = $piece->getSession();
        if (!$session || $session->getOrganismeFormation()?->getId() !== $of->getId()) {
            throw $this->createAccessDeniedException();
        }

        $filename = basename((string) $piece->getFilename());
        $path = $this->resolveSessionPiecePath($piece);

        if (!is_file($path)) {
            throw $this->createNotFoundException(sprintf('Fichier introuvable : %s', $filename));
        }

        return $this->file($path, $filename);
    }
}