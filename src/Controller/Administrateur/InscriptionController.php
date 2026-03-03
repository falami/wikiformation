<?php

namespace App\Controller\Administrateur;

use App\Entity\{Inscription, Entite, Utilisateur, DossierInscription, Attestation, Entreprise, ConventionContrat, ContratStagiaire};
use App\Enum\StatusInscription;
use App\Form\Administrateur\InscriptionType;
use App\Service\AssiduiteCalculator;
use App\Service\Pdf\PdfManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/inscription', name: 'app_administrateur_inscription_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::INSCRIPTION_MANAGE, subject: 'entite')]
class InscriptionController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
        private AssiduiteCalculator $assiduiteCalculator,
        private PdfManager $pdf,
        private string $projectDir = '',
        private string $publicDir  = '',
    ) {}

    /** Helper pour convertir un chemin absolu vers un chemin web relatif */
    private function toRelativeWebPath(string $absolute): string
    {
        $public = $this->publicDir ?: rtrim($this->projectDir, '/') . '/public';
        $public = rtrim($public, '/') . '/';

        return str_starts_with($absolute, $public)
            ? substr($absolute, strlen($public))
            : $absolute;
    }

    #[Route('/liste', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/inscription/index.html.twig', [
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
        $columns = $request->request->all('columns');

        // mapping colonnes DataTables -> champs DQL
        $map = [
            0 => 'i.id',
            1 => 'se.code',
            2 => 'st.nom',
            3 => 'i.status',
            4 => 'i.tauxAssiduite',
        ];

        $repo = $em->getRepository(Inscription::class);

        $qb = $repo->createQueryBuilder('i')
            ->leftJoin('i.session', 'se')->addSelect('se')
            ->leftJoin('se.formation', 'f')->addSelect('f')
            ->leftJoin('i.stagiaire', 'st')->addSelect('st')
            ->andWhere('se.entite = :entite') // <-- adapte ici si besoin
            ->setParameter('entite', $entite);

        // Total non filtré
        $recordsTotal = (int)(clone $qb)
            ->select('COUNT(DISTINCT i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // Recherche
        if ($searchV) {
            $qb->andWhere('
            se.code LIKE :s
            OR f.titre LIKE :s
            OR st.nom LIKE :s
            OR st.prenom LIKE :s
            OR st.email LIKE :s
        ')->setParameter('s', '%' . $searchV . '%');
        }

        // Filtre statut (optionnel)
        $statusFilter = (string) $request->request->get('statusFilter', 'all');
        if ($statusFilter !== 'all') {
            // on sécurise : on n'accepte que les valeurs enum connues
            $enum = StatusInscription::tryFrom($statusFilter);
            if ($enum) {
                $qb->andWhere('i.status = :stFilter')->setParameter('stFilter', $enum);
            }
        }


        // Total filtré
        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT i.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        // ORDER dynamique
        $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $orderDir    = isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc' ? 'ASC' : 'DESC';
        $orderBy     = $map[$orderColIdx] ?? 'i.id';

        /** @var Inscription[] $rows */
        $rows = $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = array_map(function (Inscription $i) use ($entite) {
            $session = $i->getSession();
            $stag    = $i->getStagiaire();

            $sessionStr = $session
                ? sprintf('%s - %s', $session->getCode(), $session->getFormation()?->getTitre() ?? '')
                : '-';

            $stagStr = $stag
                ? sprintf('%s %s (%s)', $stag->getPrenom(), $stag->getNom(), $stag->getEmail())
                : '-';

            $status = $i->getStatus()?->value ?? '-';
            $assid  = $i->getTauxAssiduite() !== null ? ($i->getTauxAssiduite() . '%') : '-';

            return [
                'id'         => $i->getId(),
                'session'    => $sessionStr,
                'stagiaire'  => $stagStr,
                'status'     => $this->renderView('administrateur/inscription/_status_badge.html.twig', [
                    'inscription' => $i,
                ]),
                'assiduite'  => $assid,
                'actions'    => $this->renderView('administrateur/inscription/_actions.html.twig', [
                    'inscription' => $i,
                    'entite'      => $entite,
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


    #[Route('/kpis', name: 'kpis', methods: ['GET'])]
    public function kpis(Entite $entite, Request $request, EM $em): JsonResponse
    {
        $statusFilter = (string) $request->query->get('statusFilter', 'all');
        $searchV      = trim((string) $request->query->get('search', ''));

        $repo = $em->getRepository(Inscription::class);

        $qb = $repo->createQueryBuilder('i')
            ->leftJoin('i.session', 'se')
            ->leftJoin('se.formation', 'f')
            ->leftJoin('i.stagiaire', 'st')
            ->andWhere('se.entite = :entite')
            ->setParameter('entite', $entite);

        // Search (même logique que ajax)
        if ($searchV !== '') {
            $qb->andWhere('
            se.code LIKE :s
            OR f.titre LIKE :s
            OR st.nom LIKE :s
            OR st.prenom LIKE :s
            OR st.email LIKE :s
        ')->setParameter('s', '%' . $searchV . '%');
        }

        // statusFilter (même logique que ajax)
        if ($statusFilter !== 'all') {
            $enum = StatusInscription::tryFrom($statusFilter);
            if ($enum) {
                $qb->andWhere('i.status = :stFilter')->setParameter('stFilter', $enum);
            }
        }

        // KPI aggregations
        // NB: i.status est un enumType -> on compare avec des paramètres enum
        $qb->select([
            'COUNT(DISTINCT i.id) AS countAll',
            'SUM(CASE WHEN i.status = :stTermine THEN 1 ELSE 0 END) AS countTerminees',
            'SUM(CASE WHEN i.status = :stEncours THEN 1 ELSE 0 END) AS countEncours',
            'AVG(i.tauxAssiduite) AS avgAssiduite',
            'SUM(CASE WHEN i.tauxAssiduite IS NOT NULL THEN 1 ELSE 0 END) AS withAssiduite',
        ])
            ->setParameter('stTermine', StatusInscription::TERMINE)
            ->setParameter('stEncours', StatusInscription::EN_COURS);

        $row = $qb->getQuery()->getSingleResult();

        // Doctrine peut renvoyer strings -> cast propre
        $countAll       = (int) ($row['countAll'] ?? 0);
        $countTerminees = (int) ($row['countTerminees'] ?? 0);
        $countEncours   = (int) ($row['countEncours'] ?? 0);
        $withAssiduite  = (int) ($row['withAssiduite'] ?? 0);

        $avg = $row['avgAssiduite'];
        $avgAssiduite = ($avg === null) ? null : (float) $avg;

        return new JsonResponse([
            'count'         => $countAll,
            'terminees'     => $countTerminees,
            'encours'       => $countEncours,
            'withAssiduite' => $withAssiduite,
            'avgAssiduite'  => $avgAssiduite, // ex 82.4
        ]);
    }



    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Entite $entite, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $ins = new Inscription();
        $ins->setCreateur($user);
        $ins->setEntite($entite);
        $form = $this->createForm(InscriptionType::class, $ins, [
            'entite' => $entite,
        ])->handleRequest($req);


        if ($form->isSubmitted() && $form->isValid()) {


            if (!$ins->getDossier()) {
                $dossier = new DossierInscription();
                $dossier->setCreateur($user);
                $dossier->setEntite($entite);
                $dossier->setInscription($ins);
                $ins->setDossier($dossier); // synchronise les deux côtés
                $em->persist($dossier);
            }
            $em->persist($ins);
            $em->flush();

            $this->addFlash('success', 'Inscription créée.');
            return $this->redirectToRoute('app_administrateur_inscription_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/inscription/form.html.twig', [
            'form'          => $form,
            'title'         => 'Nouvelle inscription',
            'entite'        => $entite,
            'modeEdition'   => false,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Entite $entite, Inscription $ins, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        $convention = null;

        if ($ins->getEntreprise()) {
            $convention = $em->getRepository(ConventionContrat::class)->findOneBy([
                'entite'     => $entite,
                'session'    => $ins->getSession(),
                'entreprise' => $ins->getEntreprise(),
            ]);
        }

        return $this->render('administrateur/inscription/show.html.twig', [
            'ins' => $ins,
            'entite' => $entite,
            'convention' => $convention,

        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Entite $entite, Inscription $ins, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $form = $this->createForm(InscriptionType::class, $ins, [
            'entite' => $entite,
        ])->handleRequest($req);


        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Inscription mise à jour.');

            return $this->redirectToRoute('app_administrateur_inscription_show', [
                'id'     => $ins->getId(),
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/inscription/form.html.twig', [
            'form'          => $form,
            'title'         => 'Éditer inscription',
            'entite'        => $entite,
            'modeEdition'   => true,
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(Entite $entite, Inscription $ins, Request $req, EM $em): Response
    {
        if ($this->isCsrfTokenValid('del' . $ins->getId(), $req->request->get('_token'))) {
            $em->remove($ins);
            $em->flush();
            $this->addFlash('success', 'Inscription supprimée.');
        }

        return $this->redirectToRoute('app_administrateur_inscription_index', [
            'entite' => $entite->getId(),
        ]);
    }

    #[Route('/{id}/cloturer', name: 'close', methods: ['POST'])]
    public function close(Entite $entite, Inscription $ins, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        if (!$this->isCsrfTokenValid('close' . $ins->getId(), (string) $req->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_administrateur_inscription_show', [
                'id' => $ins->getId(),
                'entite' => $entite->getId(),
            ]);
        }

        // 1) Calcul et stockage de l’assiduité
        $pct = $this->assiduiteCalculator->computeForInscription($ins);
        $ins->setTauxAssiduite($pct);

        // 2) Clôture + règle métier "réussi"
        $ins->setStatus(StatusInscription::TERMINE);
        $ins->setReussi($pct >= 75); // à adapter si besoin

        // 3) Création / mise à jour de l’attestation liée
        $attestation = $ins->getAttestation();

        if (!$attestation) {
            $attestation = new Attestation();
            $attestation->setCreateur($user);
            $attestation->setEntite($entite);
            $attestation->setInscription($ins);
            $attestation->setDateDelivrance(new \DateTimeImmutable());

            // Durée : on peut la déduire de la formation si tu as ce champ
            $formation = $ins->getSession()?->getFormation();
            $dureeJours = $formation?->getDuree() ?? 0;
            $dureeHeures = $dureeJours * 7;
            $attestation->setDureeHeures($dureeHeures);
            $attestation->setReussi($ins->isReussi());
            $em->persist($attestation);
            $em->flush(); // ⚠ pour que le numéro soit généré (attestation_sequence)
        } else {
            // Si l’attestation existe déjà, on la synchronise
            $attestation->setReussi($ins->isReussi());
            $attestation->setDateDelivrance(new \DateTimeImmutable());
        }

        // 4) Génération automatique du PDF d’attestation
        $vars = [
            'inscription'       => $ins,
            'stagiaire'         => $ins->getStagiaire(),
            'session'           => $ins->getSession(),
            'formation'         => $ins->getSession()?->getFormation(),
            'entite'            => $entite,
            'tauxAssiduite'     => $ins->getTauxAssiduite(),
            'numeroAttestation' => $attestation->getNumeroOrNull(),
        ];

        $filename     = sprintf('%s.pdf', $attestation->getNumero());
        $absolutePath = $this->pdf->attestation($vars, $filename);
        $relativePath = $this->toRelativeWebPath($absolutePath);
        $attestation->setPdfPath($relativePath);

        $em->flush();

        // 5) Feedback
        $this->addFlash(
            'success',
            sprintf('Inscription clôturée (assiduité %.1f%%). Attestation générée.', $pct)
        );

        return $this->redirectToRoute('app_administrateur_inscription_show', [
            'id'     => $ins->getId(),
            'entite' => $entite->getId(),
        ]);
    }




    #[Route(
        '/{id}/documents/generate',
        name: 'documents_generate',
        methods: ['GET']
    )]
    public function generateDocuments(
        Entite $entite,
        Inscription $ins,
        EM $em
    ): RedirectResponse {


        // sécurité entité : la session liée doit appartenir à l’entité
        $session = $ins->getSession();
        if (!$session || $session->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $mode = $ins->getModeFinancement();

        /** ===== CONVENTION ENTREPRISE ===== */
        if ($mode->requiresConvention()) {

            $entreprise = $ins->getEntreprise();
            if (!$entreprise) {
                $this->addFlash('warning', 'Entreprise manquante : impossible de créer une convention entreprise.');
                return $this->redirectToRoute('app_administrateur_session_show', [
                    'entite' => $entite->getId(),
                    'id'     => $session->getId(),
                ]);
            }

            $convention = $em->getRepository(ConventionContrat::class)->findOneBy([
                'entite'     => $entite,
                'session'    => $session,
                'entreprise' => $entreprise,
            ]);

            if (!$convention) {
                $convention = (new ConventionContrat())
                    ->setCreateur($user)
                    ->setEntite($entite)
                    ->setSession($session)
                    ->setEntreprise($entreprise);
                $em->persist($convention);
                $em->flush();
            }

            return $this->redirectToRoute('app_administrateur_convention_edit', [
                'entite' => $entite->getId(),
                'id'     => $convention->getId(),
            ]);
        }

        /** ===== CONTRAT STAGIAIRE ===== */
        if ($mode->requiresContratStagiaire()) {

            $contrat = $em->getRepository(ContratStagiaire::class)->findOneBy([
                'entite'       => $entite,
                'inscription'  => $ins,
            ]);

            if (!$contrat) {
                $contrat = (new ContratStagiaire())
                    ->setCreateur($user)
                    ->setEntite($entite)
                    ->setInscription($ins);
                $em->persist($contrat);
                $em->flush();
            }

            return $this->redirectToRoute('app_administrateur_contrat_stagiaire_edit', [
                'entite' => $entite->getId(),
                'id'     => $contrat->getId(),
            ]);
        }

        $this->addFlash('warning', 'Mode de financement non géré.');
        return $this->redirectToRoute('app_administrateur_session_show', [
            'entite' => $entite->getId(),
            'id'     => $session->getId(),
        ]);
    }


    #[Route('/entreprise/ajax/create', name: 'entreprise_ajax_create', methods: ['POST'])]
    public function ajaxCreateEntreprise(Entite $entite, Request $request, EM $em): JsonResponse
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $ue = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

        if (!$ue) {
            return $this->json(['ok' => false, 'message' => 'Accès refusé.'], 403);
        }

        $data = json_decode((string) $request->getContent(), true) ?: [];

        $raison = trim((string) ($data['raisonSociale'] ?? ''));
        $siret  = trim((string) ($data['siret'] ?? ''));
        $email  = trim((string) ($data['emailFacturation'] ?? ''));

        if ($raison === '') {
            return $this->json(['ok' => false, 'message' => 'La raison sociale est obligatoire.'], 422);
        }

        $siretDigits = preg_replace('/\D+/', '', $siret);
        if ($siretDigits !== '' && strlen($siretDigits) !== 14) {
            return $this->json(['ok' => false, 'message' => 'Le SIRET doit contenir 14 chiffres.'], 422);
        }

        // Eviter doublons (entite + raison sociale)
        $existing = $em->getRepository(Entreprise::class)->findOneBy([
            'entite' => $entite,
            'raisonSociale' => $raison,
        ]);

        if ($existing) {
            return $this->json([
                'ok' => true,
                'id' => $existing->getId(),
                'label' => $existing->getRaisonSociale(),
                'message' => 'Entreprise déjà existante.',
            ]);
        }

        $e = new Entreprise();
        $e->setCreateur($user);
        $e->setEntite($entite);
        $e->setRaisonSociale($raison);
        $e->setSiret($siretDigits ?: null);
        $e->setEmailFacturation($email ?: null);

        $em->persist($e);
        $em->flush();

        return $this->json([
            'ok' => true,
            'id' => $e->getId(),
            'label' => $e->getRaisonSociale(),
        ]);
    }



    #[Route('/{id}/set-entreprise', name: 'set_entreprise', methods: ['POST'])]
    public function setEntreprise(
        Entite $entite,
        Inscription $inscription,
        Request $request,
        EM $em
    ): JsonResponse {
        // sécurité entite
        if ($inscription->getSession()?->getEntite()?->getId() !== $entite->getId()) {
            return $this->json(['ok' => false, 'message' => 'Mismatch entité.'], 403);
        }

        if (!$this->isCsrfTokenValid('set_entreprise_inscription_' . $inscription->getId(), (string)$request->request->get('_token'))) {
            return $this->json(['ok' => false, 'message' => 'CSRF invalide.'], 400);
        }

        $eid = trim((string)$request->request->get('entrepriseId', ''));
        $entreprise = null;

        if ($eid !== '') {
            $entreprise = $em->getRepository(Entreprise::class)->find((int)$eid);
            if (!$entreprise || $entreprise->getEntite()?->getId() !== $entite->getId()) {
                return $this->json(['ok' => false, 'message' => 'Entreprise invalide.'], 400);
            }
        }

        $inscription->setEntreprise($entreprise);
        $em->flush();

        return $this->json([
            'ok' => true,
            'entreprise' => $entreprise ? [
                'id' => $entreprise->getId(),
                'label' => $entreprise->getRaisonSociale(),
            ] : null
        ]);
    }
}
