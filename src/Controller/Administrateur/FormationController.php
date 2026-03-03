<?php

namespace App\Controller\Administrateur;

use App\Entity\{Formation, Entite, Utilisateur, FormationPhoto, Categorie};
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use App\Service\FileUploader;
use Doctrine\ORM\QueryBuilder;
use App\Service\Photo\PhotoManager;
use App\Form\Administrateur\FormationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Enum\NiveauFormation;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Service\Formation\FormationCloner;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Form\Administrateur\CategorieType;
use App\Repository\CategorieRepository;



#[Route('/administrateur/{entite}/formation', name: 'app_administrateur_formation_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::FORMATION_MANAGE, subject: 'entite')]
final class FormationController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
        private FormationCloner $formationCloner,
        private EntityManagerInterface $em,
    ) {}
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        // adapte à ton modèle de droits
        return $this->render(
            'administrateur/formation/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }

    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);

        $search  = $request->request->all('search');
        $searchV = trim((string)($search['value'] ?? ''));

        // Filtres custom (envoyés par la page)
        $categorieFilter     = (string)($request->request->get('categorieFilter', 'all'));      // parent id
        $sousCategorieFilter = (string)($request->request->get('sousCategorieFilter', 'all'));  // child id
        $niveauFilter = (string)($request->request->get('niveauFilter', 'all'));
        $prixMin      = trim((string)$request->request->get('prixMin', ''));
        $prixMax      = trim((string)$request->request->get('prixMax', ''));

        // Prix: on travaille en cents
        $prixMinCents = ($prixMin !== '' && is_numeric($prixMin)) ? (int) round(((float)$prixMin) * 100) : null;
        $prixMaxCents = ($prixMax !== '' && is_numeric($prixMax)) ? (int) round(((float)$prixMax) * 100) : null;

        $repo = $em->getRepository(Formation::class);

        // Helper: applique tous les filtres au QueryBuilder
        // ---------

        $applyFilters = function (QueryBuilder $qb) use (
            $entite,
            $searchV,
            $sousCategorieFilter,
            $categorieFilter,
            $niveauFilter,
            $prixMinCents,
            $prixMaxCents
        ): void {
            $qb->andWhere('f.entite = :entite')
                ->setParameter('entite', $entite);

            if (!in_array('c', $qb->getAllAliases(), true)) {
                $qb->leftJoin('f.categorie', 'c');
            }
            if (!in_array('p', $qb->getAllAliases(), true)) {
                $qb->leftJoin('c.parent', 'p');
            }

            if ($searchV !== '') {
                $qb->andWhere('f.titre LIKE :s OR f.slug LIKE :s')
                    ->setParameter('s', '%' . $searchV . '%');
            }

            if ($sousCategorieFilter !== 'all' && ctype_digit($sousCategorieFilter)) {
                $qb->andWhere('c.id = :subId')->setParameter('subId', (int)$sousCategorieFilter);
            } elseif ($categorieFilter !== 'all' && ctype_digit($categorieFilter)) {
                $qb->andWhere('(c.id = :catId OR p.id = :catId)')
                    ->setParameter('catId', (int)$categorieFilter);
            }

            if ($niveauFilter !== 'all') {
                $qb->andWhere('f.niveau = :niv')->setParameter('niv', $niveauFilter);
            }
            if ($prixMinCents !== null) {
                $qb->andWhere('f.prixBaseCents >= :pmin')->setParameter('pmin', $prixMinCents);
            }
            if ($prixMaxCents !== null) {
                $qb->andWhere('f.prixBaseCents <= :pmax')->setParameter('pmax', $prixMaxCents);
            }
        };


        // ---------
        // recordsTotal (sans recherche/filtres)
        // ---------
        $recordsTotal = (int) $repo->createQueryBuilder('f')
            ->select('COUNT(DISTINCT f.id)')
            ->andWhere('f.entite = :entite')
            ->setParameter('entite', $entite)
            ->getQuery()->getSingleScalarResult();


        // ---------
        // recordsFiltered (avec filtres)
        // ---------
        $qbCount = $repo->createQueryBuilder('f');
        $applyFilters($qbCount);

        $recordsFiltered = (int) $qbCount
            ->select('COUNT(DISTINCT f.id)')
            ->getQuery()->getSingleScalarResult();


        // ---------
        // rows (avec nbSessions)
        // ---------
        $qbRows = $repo->createQueryBuilder('f')
            ->leftJoin('f.sessions', 'se')
            ->leftJoin('f.categorie', 'c')
            ->leftJoin('c.parent', 'p')
            ->addSelect('COUNT(DISTINCT se.id) AS nbSessions');

        $applyFilters($qbRows);

        // Sorting DataTables (optionnel mais propre)
        $order = $request->request->all('order');
        $columns = $request->request->all('columns');

        $colMap = [
            'id'           => 'f.id',
            'titre'        => 'f.titre',
            'categorie'    => 'COALESCE(p.nom, c.nom)',     // tri “parent d’abord”
            'sousCategorie' => 'c.nom',
            'niveau'       => 'f.niveau',
            'prixBase'     => 'f.prixBaseCents',
            'duree'        => 'f.duree',
            'nbSessions'   => 'nbSessions',
        ];


        $orderByApplied = false;
        if (is_array($order) && isset($order[0]['column'], $order[0]['dir'])) {
            $colIdx = (int)$order[0]['column'];
            $dir = strtolower((string)$order[0]['dir']) === 'asc' ? 'ASC' : 'DESC';

            $dataKey = $columns[$colIdx]['data'] ?? null; // ex: "titre"
            if ($dataKey && isset($colMap[$dataKey])) {
                $qbRows->orderBy($colMap[$dataKey], $dir);
                $orderByApplied = true;
            }
        }
        if (!$orderByApplied) {
            $qbRows->orderBy('f.id', 'DESC');
        }

        $rows = $qbRows
            ->groupBy('f.id, c.id, p.id')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult(); // tableau: [0 => Formation, 'nbSessions' => int]

        $data = array_map(function ($row) use ($entite) {
            /** @var Formation $f */
            $f = is_array($row) ? $row[0] : $row;
            $nbSessions = is_array($row) ? (int)$row['nbSessions'] : $f->getSessions()->count();

            $prixCents = $f->getPrixBaseCents() ?? 0;
            $prixBase  = number_format($prixCents / 100, 2, ',', ' ') . ' €';

            if ($f->getNote() !== null) {
                $titre = sprintf(
                    '<div class="fw-semibold">%s</div>
                    <div class="mt-1">
                    <span class="badge rounded-pill bg-primary">%s</span>
                    </div>',
                    htmlspecialchars($f->getTitre(), ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($f->getNote(), ENT_QUOTES, 'UTF-8'),
                );
            } else {
                $titre = $f->getTitre();
            }
            return [
                'id'         => $f->getId(),
                'titre'      => $titre,
                'categorie' => $f->getCategorie()
                    ? ($f->getCategorie()->getParent()
                        ? $f->getCategorie()->getParent()->getNom()
                        : $f->getCategorie()->getNom())
                    : '—',

                'sousCategorie' => $f->getCategorie()
                    ? ($f->getCategorie()->getParent()
                        ? $f->getCategorie()->getNom()
                        : '—')
                    : '—',

                'niveau'     => $f->getNiveau()?->label() ?? '—',   // affichage label
                'niveauRaw'  => $f->getNiveau()?->value ?? null,    // utile si tu veux
                'enginId'    => $f?->getEngin()?->getId() ?? null,  // utile si tu veux
                'prixBase'   => $prixBase,
                'duree'      => ($f->getDuree() !== null ? $f->getDuree() . 'j' : '—'),
                'nbSessions' => $nbSessions,
                'actions'    => $this->renderView('administrateur/formation/_actions.html.twig', [
                    'formation' => $f,
                    'entite'    => $entite,
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






    #[Route('/meta', name: 'meta', methods: ['GET'])]
    public function meta(Entite $entite, EntityManagerInterface $em): JsonResponse
    {


        $cats = $em->getRepository(Categorie::class)->createQueryBuilder('c')
            ->select('c.id, c.nom, IDENTITY(c.parent) AS parentId')
            ->andWhere('c.entite = :e')->setParameter('e', $entite)
            ->orderBy('parentId', 'ASC')
            ->addOrderBy('c.nom', 'ASC')
            ->getQuery()->getArrayResult();


        $parents = [];
        $subsByParent = [];

        foreach ($cats as $c) {
            $id = (int)$c['id'];
            $label = (string)$c['nom'];
            $parentId = $c['parentId'] !== null ? (int)$c['parentId'] : null;

            if ($parentId === null) {
                $parents[] = ['id' => $id, 'label' => $label];
            } else {
                $subsByParent[(string)$parentId] ??= [];
                $subsByParent[(string)$parentId][] = ['id' => $id, 'label' => $label];
            }
        }

        $niveaux = array_map(
            fn(NiveauFormation $n) => ['value' => $n->value, 'label' => $n->label()],
            NiveauFormation::cases()
        );

        return new JsonResponse([
            'categoriesParents'    => $parents,
            'subCategoriesByParent' => $subsByParent,
            'niveaux'              => $niveaux,
        ]);
    }



    #[Route('/ajouter', name: 'ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'modifier', methods: ['GET', 'POST'])]
    public function addEdit(
        Entite $entite,
        Request $request,
        EntityManagerInterface $em,
        ?Formation $formation = null
    ): Response {


        /** @var Utilisateur $user */
        $user   = $this->getUser();
        $isEdit = (bool) $formation;

        // ✅ si création : on set l'entité tout de suite (important pour l’unicité par entité)
        $formation ??= (new Formation())->setEntite($entite)->setCreateur($user);

        // ✅ si édition : s’assure que l’entité est bien posée
        if ($formation->getEntite() === null) {
            $formation->setEntite($entite);
        }

        $form = $this->createForm(FormationType::class, $formation, [
            'entite' => $entite,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // =========================
            // 1) SLUG UNIQUE (BEST)
            // =========================
            $slugger = new AsciiSlugger('fr');

            $rawSlug = trim((string) $formation->getSlug());
            if ($rawSlug === '') {
                $rawSlug = trim((string) $formation->getTitre());
            }

            $base = strtolower((string) $slugger->slug($rawSlug));
            $base = preg_replace('/-+/', '-', $base) ?: 'formation';

            $candidate = $base;
            $i = 2;

            // petit helper : slug existe déjà dans la même entité ?
            $slugExists = function (string $slug) use ($em, $entite, $formation, $isEdit): bool {
                $qb = $em->createQueryBuilder()
                    ->select('COUNT(f.id)')
                    ->from(Formation::class, 'f')
                    ->andWhere('f.entite = :e')->setParameter('e', $entite)
                    ->andWhere('f.slug = :s')->setParameter('s', $slug);

                if ($isEdit && $formation->getId()) {
                    $qb->andWhere('f.id != :id')->setParameter('id', $formation->getId());
                }

                return (int) $qb->getQuery()->getSingleScalarResult() > 0;
            };

            while ($slugExists($candidate)) {
                $candidate = $base . '-' . $i;
                $i++;
                if ($i > 5000) { // sécurité
                    $candidate = $base . '-' . uniqid();
                    break;
                }
            }

            $formation->setSlug($candidate);

            // =========================
            // 2) UPLOADS
            // =========================
            $uploadPath = $this->getParameter('formation_upload_dir');

            // Photo couverture (1600x600)
            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photoCouverture',
                setter: fn(string $name) => $formation->setPhotoCouverture($name),
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 1600,
                sizeH: 600,
                oldFilename: $formation->getPhotoCouverture()
            );

            // Photo bannière (360x240)
            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photoBanniere',
                setter: fn(string $name) => $formation->setPhotoBanniere($name),
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 360,
                sizeH: 240,
                oldFilename: $formation->getPhotoBanniere()
            );

        // Galerie (1600x900)
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[]|null $galleryFiles */
            $galleryFiles = $form->get('galleryFiles')->getData();
            if ($galleryFiles) {
                $pos = $formation->getPhotos()->count();
                $imagine = new Imagine();

                foreach ($galleryFiles as $file) {
                    $filename = $this->fileUploader->upload($file, $uploadPath);

                    $imagine->open($uploadPath . '/' . $filename)
                        ->thumbnail(new Box(1600, 900), ImageInterface::THUMBNAIL_OUTBOUND)
                        ->save($uploadPath . '/' . $filename);

                    $photo = (new FormationPhoto())
                        ->setFilename($filename)
                        ->setCreateur($user)
                        ->setPosition($pos++)
                        ->setEntite($entite);

                    $formation->addPhoto($photo);
                }
            }

            // =========================
            // 3) FLUSH robuste (anti collision)
            // =========================
            try {
                $em->persist($formation);
                $em->flush();
            } catch (UniqueConstraintViolationException $e) {
                // collision concurrente rare => on force un slug unique en ajoutant un suffixe court
                $formation->setSlug($candidate . '-' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4));
                $em->persist($formation);
                $em->flush();
            }

            return $this->redirectToRoute('app_administrateur_formation_index', [
                'entite'    => $entite->getId(),
                'formation' => $formation->getId(),
            ]);
        }

        return $this->render('administrateur/formation/form.html.twig', [
            'form'              => $form->createView(),
            'modeEdition'       => $isEdit,
            'formation'         => $formation,
            'entite'            => $entite,

        ]);
    }

    #[Route('/dupliquer/{id}', name: 'dupliquer', methods: ['GET'])]
    public function duplicate(Entite $entite, EntityManagerInterface $em, Formation $formation): RedirectResponse
    {


        // (optionnel) sécurité : vérifier que la formation appartient à l'entité
        if ($formation->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createAccessDeniedException('Formation hors entité.');
        }

        /** @var Utilisateur $user */
        $user   = $this->getUser();

        $copy = $this->formationCloner->cloneFormation($formation, $user, $entite);

        $em->persist($copy);
        $em->flush();

        $this->addFlash('success', 'Formation dupliquée (#' . $copy->getId() . ').');

        // très pratique : rediriger direct vers les chapitres de la copie
        return $this->redirectToRoute('app_administrateur_formation_index', [
            'entite'    => $entite->getId(),
            'formation' => $copy->getId(),
        ]);
    }


    #[Route('/supprimer/{id}', name: 'supprimer', methods: ['POST'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Formation $formation, Request $request): RedirectResponse
    {


        if (!$this->isCsrfTokenValid('delete_formation_' . $formation->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('CSRF invalide');
        }

        $id = $formation->getId();
        $em->remove($formation);
        $em->flush();

        $this->addFlash('success', 'Formation #' . $id . ' supprimée.');
        return $this->redirectToRoute('index', [
            'entite' => $entite->getId()
        ]);
    }



    #[Route('/{id}/photos/reorder', name: 'photos_reorder', methods: ['POST'])]
    public function reorderPhotos(
        Entite $entite,
        Formation $formation,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {


        $payload = json_decode($request->getContent() ?: '[]', true);
        $order   = $payload['order'] ?? null; // attendu: [photoId1, photoId2, ...]

        if (!\is_array($order) || empty($order)) {
            return new JsonResponse(['success' => false, 'message' => 'Ordre invalide'], 400);
        }

        // Map de sécurité : on ne touche qu’aux photos de CETTE formation
        $photosById = [];
        foreach ($formation->getPhotos() as $p) {
            $photosById[$p->getId()] = $p;
        }

        $position = 0;
        foreach ($order as $pid) {
            $pid = (int)$pid;
            if (!isset($photosById[$pid])) continue;
            $photosById[$pid]->setPosition($position++);
        }

        $em->flush();

        return new JsonResponse(['success' => true]);
    }



    #[Route('/photo/{id}/supprimer', name: 'photo_supprimer', methods: ['POST'])]
    public function deletePhoto(
        Entite $entite,
        FormationPhoto $photo,
        EntityManagerInterface $em,
        Request $request
    ): Response {


        // (Optionnel) CSRF :
        // if (!$this->isCsrfTokenValid('del_photo_'.$photo->getId(), $request->request->get('_token'))) {
        //     throw $this->createAccessDeniedException('CSRF invalide');
        // }

        // Supprimer le fichier physique
        $uploadPath = rtrim($this->getParameter('formation_upload_dir'), '/');
        $filepath = $uploadPath . '/' . $photo->getFilename();
        if (is_file($filepath)) @unlink($filepath);

        // Supprimer l’entité
        $formation = $photo->getFormation();
        $em->remove($photo);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        $this->addFlash('success', 'Photo supprimée.');
        // Redirige vers l’édition de la formation (plus pratique)
        return $this->redirectToRoute('modifier', [
            'entite' => $entite->getId(),
            'id'     => $formation?->getId(),
        ]);
    }


    #[Route('/categorie', name: 'categorie', methods: ['GET'])]
    public function categorie(Entite $entite, CategorieRepository $repo): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        return $this->render('administrateur/formation/categorie/index.html.twig', [
            'entite' => $entite,
            'categories' => $repo->findByEntiteOrdered($entite),
        ]);
    }

    #[Route('/categorie/ajouter', name: 'categorie_ajouter', methods: ['GET', 'POST'])]
    #[Route('/categorie/modifier/{id}', name: 'categorie_modifier', methods: ['GET', 'POST'])]
    public function addEditCategorie(Entite $entite, Request $request, ?Categorie $categorie = null): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        $modeEdition = (bool) $categorie;
        $categorie ??= new Categorie();

        if (!$modeEdition) {
            $categorie->setEntite($entite);
            $categorie->setCreateur($user);
        }

        $oldPhoto = $categorie->getPhoto(); // nom de fichier existant

        $form = $this->createForm(CategorieType::class, $categorie, [
            'entite' => $entite,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Upload + resize (ex: 1200x600, adapte si tu veux carré 900x900)
            $this->photoManager->handleImageUpload(
                $form,
                'photo',
                fn(string $filename) => $categorie->setPhoto($filename),
                $this->fileUploader,
                $this->getParameter('upload_categorie_dir'),
                1200,
                600,
                $oldPhoto
            );

            $this->em->persist($categorie);
            $this->em->flush();

            $this->addFlash('success', $modeEdition ? 'Catégorie modifiée.' : 'Catégorie créée.');
            return $this->redirectToRoute('categorie_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/formation/categorie/form.html.twig', [
            'entite' => $entite,
            'categorie' => $categorie,
            'form' => $form->createView(),
            'modeEdition' => $modeEdition,
        ]);
    }

    #[Route('/categorie/supprimer/{id}', name: 'categorie_supprimer', methods: ['POST'])]
    public function deleteCategorie(Entite $entite, Request $request, Categorie $categorie): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();


        if ($this->isCsrfTokenValid('delete_categorie_' . $categorie->getId(), (string) $request->request->get('_token'))) {
            // supprime la photo physique
            if ($categorie->getPhoto()) {
                $this->photoManager->deleteImageIfExists(
                    $categorie->getPhoto(),
                    $this->getParameter('upload_categorie_dir')
                );
            }

            $this->em->remove($categorie);
            $this->em->flush();


            $this->addFlash('success', 'Catégorie supprimée.');
        }

        return $this->redirectToRoute('categorie_index', [
            'entite' => $entite->getId(),
        ]);
    }
}
