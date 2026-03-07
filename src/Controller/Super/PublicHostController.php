<?php

declare(strict_types=1);

namespace App\Controller\Super;

use App\Entity\PublicHost;
use App\Form\Super\PublicHostType;
use App\Repository\PublicHostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/super/public-host', name: 'app_super_public_host_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
final class PublicHostController extends AbstractController
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $publicHostLogoDir,
        private readonly string $publicHostLogoPublicPrefix,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('super/public_host/index.html.twig');
    }

    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Request $request, PublicHostRepository $repository): JsonResponse
    {
        $draw = max(1, (int) $request->request->get('draw', 1));
        $start = max(0, (int) $request->request->get('start', 0));
        $length = max(1, (int) $request->request->get('length', 25));

        $search = $request->request->all('search');
        $searchValue = trim((string) ($search['value'] ?? ''));

        $statusFilter = (string) $request->request->get('statusFilter', 'all');
        $moduleFilter = (string) $request->request->get('moduleFilter', 'all');

        $columns = [
            0 => 'ph.id',
            1 => 'ph.name',
            2 => 'e.nom',
            3 => 'ph.host',
            4 => 'ph.isActive',
            5 => 'ph.catalogueEnabled',
            6 => 'ph.calendarEnabled',
            7 => 'ph.elearningEnabled',
            8 => 'ph.shopEnabled',
            9 => 'ph.restrictToAssignedFormations',
            10 => 'formationsCount',
        ];

        $order = $request->request->all('order');
        $orderColIndex = isset($order[0]['column']) ? (int) $order[0]['column'] : 0;
        $orderDir = strtolower((string) ($order[0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $orderBy = $columns[$orderColIndex] ?? 'ph.id';

        $baseQb = $repository->createDataTableFilteredQb($searchValue, $statusFilter, $moduleFilter);

        $recordsFiltered = (int) (clone $baseQb)
            ->resetDQLPart('select')
            ->resetDQLPart('orderBy')
            ->resetDQLPart('groupBy')
            ->select('COUNT(DISTINCT ph.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $recordsTotal = (int) $repository->count([]);

        $qb = clone $baseQb;

        if ($orderBy === 'formationsCount') {
            $qb->orderBy('formationsCount', $orderDir);
        } else {
            $qb->orderBy($orderBy, $orderDir);
        }

        /** @var PublicHost[] $rows */
        $rows = $qb
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()
            ->getResult();

        $data = array_map(function (PublicHost $host): array {
            $formationsCount = $host->getFormations()->count();

            $yesBadge = static fn (bool $value): string => sprintf(
                '<span class="tiny-badge %s">%s</span>',
                $value ? 'yes' : 'no',
                $value ? 'Oui' : 'Non'
            );

            $entiteLabel = $host->getEntite()
                ? '<span class="fw-semibold">' . htmlspecialchars($host->getEntite()->getNom(), ENT_QUOTES, 'UTF-8') . '</span>'
                : '<span class="text-muted">—</span>';

            return [
                'id' => '<span class="fw-bold">#' . $host->getId() . '</span>',
                'name' => '<div class="host-name">' . htmlspecialchars($host->getName(), ENT_QUOTES, 'UTF-8') . '</div>',
                'entite' => $entiteLabel,
                'host' => '<span class="host-code"><i class="bi bi-link-45deg"></i> ' . htmlspecialchars($host->getHost(), ENT_QUOTES, 'UTF-8') . '</span>',

                'isActive' => $yesBadge($host->isActive()),
                'catalogueEnabled' => $yesBadge($host->isCatalogueEnabled()),
                'calendarEnabled' => $yesBadge($host->isCalendarEnabled()),
                'elearningEnabled' => $yesBadge($host->isElearningEnabled()),
                'shopEnabled' => $yesBadge($host->isShopEnabled()),
                'restrictToAssignedFormations' => $yesBadge($host->isRestrictToAssignedFormations()),

                'formationsCount' => '<span class="host-count">' . $formationsCount . '</span>',

                'actions' => $this->renderView('super/public_host/_actions.html.twig', [
                    'host' => $host,
                ]),

                'isActiveRaw' => $host->isActive() ? 1 : 0,
                'catalogueEnabledRaw' => $host->isCatalogueEnabled() ? 1 : 0,
                'calendarEnabledRaw' => $host->isCalendarEnabled() ? 1 : 0,
                'elearningEnabledRaw' => $host->isElearningEnabled() ? 1 : 0,
                'shopEnabledRaw' => $host->isShopEnabled() ? 1 : 0,
                'restrictToAssignedFormationsRaw' => $host->isRestrictToAssignedFormations() ? 1 : 0,
                'formationsCountRaw' => $formationsCount,
            ];
        }, $rows);

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
    public function add(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $host = new PublicHost();

        $form = $this->createForm(PublicHostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleLogoUpload($request, $host, $slugger);

            $em->persist($host);
            $em->flush();

            $this->addFlash('success', 'Host white-label créé avec succès.');

            return $this->redirectToRoute('app_super_public_host_index');
        }

        return $this->render('super/public_host/form.html.twig', [
            'form' => $form->createView(),
            'host' => $host,
            'modeEdition' => false,
        ]);
    }

    #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(
        PublicHost $host,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
    ): Response {
        $form = $this->createForm(PublicHostType::class, $host);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleLogoUpload($request, $host, $slugger);

            $em->flush();

            $this->addFlash('success', 'Host white-label modifié avec succès.');

            return $this->redirectToRoute('app_super_public_host_index');
        }

        return $this->render('super/public_host/form.html.twig', [
            'form' => $form->createView(),
            'host' => $host,
            'modeEdition' => true,
        ]);
    }

    #[Route('/voir/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(PublicHost $host): Response
    {
        return $this->render('super/public_host/show.html.twig', [
            'host' => $host,
        ]);
    }

    #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(
        PublicHost $host,
        Request $request,
        EntityManagerInterface $em,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid(
            'delete_public_host_' . $host->getId(),
            (string) $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException('CSRF invalide.');
        }

        $this->deleteLogoFileIfExists($host);

        $em->remove($host);
        $em->flush();

        $this->addFlash('success', 'Host white-label supprimé avec succès.');

        return $this->redirectToRoute('app_super_public_host_index');
    }

    private function handleLogoUpload(
        Request $request,
        PublicHost $host,
        SluggerInterface $slugger,
    ): void {
        $formFiles = $request->files->get('public_host') ?? [];
        $formData = $request->request->all('public_host');

        /** @var UploadedFile|null $logoFile */
        $logoFile = $formFiles['logoFile'] ?? null;
        $removeLogo = (string) ($formData['removeLogo'] ?? '');

        if (!is_dir($this->publicHostLogoDir) && !mkdir($this->publicHostLogoDir, 0775, true) && !is_dir($this->publicHostLogoDir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier "%s".', $this->publicHostLogoDir));
        }

        if ($removeLogo === '1') {
            $this->deleteLogoFileIfExists($host);
            $host->setLogoPath(null);
        }

        if (!$logoFile instanceof UploadedFile) {
            return;
        }

        $this->deleteLogoFileIfExists($host);

        $originalName = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
        $safeFilename = (string) $slugger->slug($originalName ?: 'logo');
        $extension = $logoFile->guessExtension() ?: 'bin';

        $filename = sprintf(
            '%s-%s.%s',
            $safeFilename,
            bin2hex(random_bytes(6)),
            $extension
        );

        try {
            $logoFile->move($this->publicHostLogoDir, $filename);
        } catch (FileException $e) {
            throw new \RuntimeException('Le téléchargement du logo a échoué.', 0, $e);
        }

        $host->setLogoPath($this->publicHostLogoPublicPrefix . '/' . $filename);
    }

    private function deleteLogoFileIfExists(PublicHost $host): void
    {
        $logoPath = $host->getLogoPath();

        if (!$logoPath) {
            return;
        }

        $absolutePath = $this->projectDir . '/public/' . ltrim($logoPath, '/');

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}