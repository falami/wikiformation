<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{Attestation, Entite, Inscription, Utilisateur};
use App\Enum\StatusInscription;
use App\Form\Administrateur\AttestationType;
use App\Service\Pdf\PdfManager;
use App\Service\AssiduiteCalculator;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\{Request, Response, ResponseHeaderBag};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/attestation', name: 'app_administrateur_attestation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::ATTESTATION_MANAGE, subject: 'entite')]
class AttestationController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private PdfManager $pdf,
        private AssiduiteCalculator $assiduiteCalculator,
        private string $projectDir = '',
        private string $publicDir  = ''
    ) {}

    private function assertSameEntite(Entite $entite, Attestation $a): void
    {
        if ($a->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /** Petit helper pour relative path */
    private function toRelativeWebPath(string $absolute): string
    {
        $public = $this->publicDir ?: rtrim($this->projectDir, '/') . '/public';
        $public = rtrim($public, '/') . '/';
        return str_starts_with($absolute, $public)
            ? substr($absolute, strlen($public))
            : $absolute; // fallback (si jamais)
    }

    /**
     * Vérifie les conditions liées à l'inscription avant génération PDF :
     * - attestation liée à une inscription
     * - inscription en status TERMINE
     * - calcule / met à jour le taux d'assiduité
     * - message d'avertissement si assiduité < 100 %
     *
     * Retourne une Response de redirection si blocage, sinon null (tout est OK).
     */
    private function checkInscriptionBeforeGenerate(Entite $entite, Attestation $a, EM $em): ?Response
    {
        $insc = $a->getInscription();
        if (!$insc instanceof Inscription) {
            $this->addFlash(
                'danger',
                'Cette attestation n’est pas liée à une inscription : impossible de vérifier l’assiduité.'
            );

            return $this->redirectToRoute('app_administrateur_attestation_index', [
                'entite' => $entite->getId(),
            ]);
        }

        // 1. L’inscription doit être clôturée
        if ($insc->getStatus() !== StatusInscription::TERMINE) {
            $this->addFlash(
                'warning',
                'Attention : l’inscription liée à cette attestation n’est pas clôturée. '
                    . 'Merci de clôturer l’inscription (calcul de l’assiduité, validation de la réussite) '
                    . 'avant de générer / transmettre l’attestation.'
            );

            return $this->redirectToRoute('app_administrateur_inscription_show', [
                'entite' => $entite->getId(),
                'id'     => $insc->getId(),
            ]);
        }

        // 2. Calcul / mise à jour du taux d’assiduité si besoin
        $pct = $insc->getTauxAssiduite();
        if ($pct === null) {
            $pct = $this->assiduiteCalculator->computeForInscription($insc);
            $em->flush();
        }

        // 3. Synchroniser éventuellement l'état "réussi" sur l'attestation
        if ($a->isReussi() !== $insc->isReussi()) {
            $a->setReussi($insc->isReussi());
            $em->flush();
        }

        // 4. Message d’alerte si assiduité non complète
        if ($pct < 100) {
            $this->addFlash(
                'warning',
                sprintf(
                    'Attention : le taux d’assiduité du stagiaire est de %.1f%%. '
                        . 'Merci de vérifier que toutes les feuilles d’émargement sont bien saisies '
                        . 'avant de transmettre l’attestation.',
                    $pct
                )
            );
        }

        return null; // tout est OK, on peut générer le PDF
    }

    /**
     * Construit les variables passées au template pdf/attestation.html.twig
     * en accord avec ce que tu utilises dans Twig :
     *
     *  - inscription
     *  - stagiaire
     *  - session
     *  - formation
     *  - entite
     *  - tauxAssiduite
     *  - numeroAttestation (facultatif pour Twig, si tu veux l’utiliser plus tard)
     */
    private function buildPdfVars(Attestation $a, Entite $entite): array
    {
        $insc = $a->getInscription();
        $stag = $insc?->getStagiaire();
        $sess = $insc?->getSession();
        $form = $sess?->getFormation();

        return [
            'inscription'      => $insc,
            'stagiaire'        => $stag,
            'session'          => $sess,
            'formation'        => $form,
            'entite'           => $entite,
            'tauxAssiduite'    => $insc?->getTauxAssiduite(),
            // au cas où tu veuilles un jour l’exploiter dans Twig :
            'numeroAttestation' => $a->getNumeroOrNull(),
        ];
    }

    /** Génère le PDF sur disque + set pdfPath (relatif web) */
    private function generateAndPersistPdf(Attestation $a, Entite $entite, EM $em): void
    {
        // nom de fichier propre basé sur le numéro généré via attestation_sequence
        $filename = sprintf('%s.pdf', $a->getNumero());

        $absolutePath = $this->pdf->attestation(
            $this->buildPdfVars($a, $entite),
            $filename
        );

        $relative = $this->toRelativeWebPath($absolutePath);
        $a->setPdfPath($relative);
        $em->flush();
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/attestation/index.html.twig', [
            'entite' => $entite,
        ]);
    }




    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EM $em): JsonResponse
    {

        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);
        $search  = $request->request->all('search');
        $searchV = $search['value'] ?? '';

        $order   = $request->request->all('order');

        // mapping colonnes DataTables -> champs DQL (0..7)
        $map = [
            0 => 'a.numero',
            1 => 'ins.id',
            3 => 'a.dureeHeures',
            4 => 'a.reussi',
            5 => 'a.dateDelivrance',
        ];

        $repo = $em->getRepository(Attestation::class);

        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.inscription', 'ins')->addSelect('ins')
            ->leftJoin('ins.session', 'se')->addSelect('se')
            ->leftJoin('se.formation', 'f')->addSelect('f')
            ->leftJoin('ins.stagiaire', 'st')->addSelect('st')
            ->andWhere('a.entite = :entite')
            ->setParameter('entite', $entite);

        // total non filtré
        $recordsTotal = (int)(clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // search (large)
        if ($searchV) {
            $qb->andWhere('
            a.numero LIKE :s
            OR CAST(a.dureeHeures AS string) LIKE :s
            OR (CASE WHEN a.reussi = true THEN \'oui\' ELSE \'non\' END) LIKE :s
            OR st.nom LIKE :s
            OR st.prenom LIKE :s
            OR st.email LIKE :s
            OR se.code LIKE :s
            OR f.titre LIKE :s
        ')->setParameter('s', '%' . $searchV . '%');
        }

        // total filtré
        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // order dynamique
        $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $orderDir    = isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc' ? 'ASC' : 'DESC';
        $orderBy     = $map[$orderColIdx] ?? 'a.id';

        /** @var Attestation[] $rows */
        $rows = $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = array_map(function (Attestation $a) use ($entite) {

            $ins = $a->getInscription();

            // Colonne "Inscription" (plus riche que juste #id)
            $inscriptionStr = $ins
                ? sprintf(
                    '#%d - %s - %s',
                    $ins->getId(),
                    $ins->getSession()?->getCode() ?? '-',
                    $ins->getStagiaire()
                        ? trim($ins->getStagiaire()->getPrenom() . ' ' . $ins->getStagiaire()->getNom())
                        : '-'
                )
                : '-';

            return [
                'numero'        => $a->getNumero() ?? '-',
                'inscription'   => $inscriptionStr,
                'dureeHeures'   => $a->getDureeHeures() ?? '-',
                'reussi'        => $this->renderView('administrateur/attestation/_reussi_badge.html.twig', [
                    'a' => $a,
                ]),
                'dateDelivrance' => $a->getDateDelivrance()
                    ? $a->getDateDelivrance()->format('d/m/Y')
                    : '-',
                'actions'       => $this->renderView('administrateur/attestation/_actions.html.twig', [
                    'a'     => $a,
                    'entite' => $entite,
                ]),
            ];
        }, $rows);

        return new JsonResponse([
            'draw'            => $request->request->getInt('draw', 0),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }



    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Entite $entite, Request $request, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $a = new Attestation();
        $a->setEntite($entite);
        $a->setCreateur($user);
        $a->setDateDelivrance(new \DateTimeImmutable());

        $form = $this->createForm(AttestationType::class, $a)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            // Si une inscription est liée, on synchronise déjà "reussi"
            if ($a->getInscription() instanceof Inscription) {
                $a->setReussi($a->getInscription()->isReussi());
            }

            $em->persist($a);
            $em->flush(); // -> id + numero généré via listener

            // Vérification conditions (inscription terminée / assiduité)
            if ($resp = $this->checkInscriptionBeforeGenerate($entite, $a, $em)) {
                return $resp;
            }

            // Génère le PDF + set pdfPath
            $this->generateAndPersistPdf($a, $entite, $em);

            $this->addFlash('success', 'Attestation créée et PDF généré.');
            return $this->redirectToRoute('app_administrateur_attestation_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/attestation/form.html.twig', [
            'entite' => $entite,
            'form'   => $form,
            'title'  => 'Nouvelle attestation',
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Entite $entite, Attestation $attestation, Request $request, EM $em): Response
    {
        $this->assertSameEntite($entite, $attestation);
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $form = $this->createForm(AttestationType::class, $attestation)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            // resynchroniser éventuellement avec l’inscription
            if ($attestation->getInscription() instanceof Inscription) {
                $attestation->setReussi($attestation->getInscription()->isReussi());
            }

            $em->flush();

            // Vérification avant regénération
            if ($resp = $this->checkInscriptionBeforeGenerate($entite, $attestation, $em)) {
                return $resp;
            }

            // Regénère le PDF
            $this->generateAndPersistPdf($attestation, $entite, $em);

            $this->addFlash('success', 'Attestation mise à jour et PDF regénéré.');
            return $this->redirectToRoute('app_administrateur_attestation_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/attestation/form.html.twig', [
            'entite' => $entite,
            'form'   => $form,
            'title'  => sprintf('Modifier attestation #%d', $attestation->getId()),
        ]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Entite $entite, Attestation $attestation, EM $em): Response
    {
        $this->assertSameEntite($entite, $attestation);
        // Vérification des conditions inscription / assiduité
        if ($resp = $this->checkInscriptionBeforeGenerate($entite, $attestation, $em)) {
            return $resp;
        }

        $public = $this->publicDir ?: rtrim($this->projectDir, '/') . '/public';
        $fs = new Filesystem();

        // Génère si non présent
        if (
            !$attestation->getPdfPath() ||
            !$fs->exists($public . '/' . ltrim($attestation->getPdfPath(), '/'))
        ) {
            $this->generateAndPersistPdf($attestation, $entite, $em);
        }

        $abs = $public . '/' . ltrim($attestation->getPdfPath(), '/');
        return $this->file($abs, pathinfo($abs, PATHINFO_BASENAME), ResponseHeaderBag::DISPOSITION_INLINE);
    }

    #[Route('/{id}/regenerer', name: 'regenerate_pdf', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerate(Entite $entite, Attestation $attestation, EM $em): Response
    {
        $this->assertSameEntite($entite, $attestation);
        if ($resp = $this->checkInscriptionBeforeGenerate($entite, $attestation, $em)) {
            return $resp;
        }

        $this->generateAndPersistPdf($attestation, $entite, $em);
        $this->addFlash('success', 'PDF regénéré.');
        return $this->redirectToRoute('app_administrateur_attestation_index', [
            'entite' => $entite->getId(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Entite $entite, Attestation $attestation, Request $request, EM $em): Response
    {
        $this->assertSameEntite($entite, $attestation);
        /** @var Utilisateur $user */
        $user = $this->getUser();
        if ($this->isCsrfTokenValid('del_attestation_' . $attestation->getId(), $request->request->get('_token'))) {
            $em->remove($attestation);
            $em->flush();
            $this->addFlash('success', 'Attestation supprimée.');
        } else {
            $this->addFlash('danger', 'Token CSRF invalide.');
        }

        return $this->redirectToRoute('app_administrateur_attestation_index', [
            'entite' => $entite->getId(),
        ]);
    }
}
