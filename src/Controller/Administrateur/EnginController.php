<?php

namespace App\Controller\Administrateur;

use App\Entity\{Engin, Entite, Utilisateur, EnginPhoto};
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Service\Email\MailerManager;
use App\Form\Administrateur\EnginType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/engin')]
#[IsGranted(TenantPermission::ENGIN_MANAGE, subject: 'entite')]
final class EnginController extends AbstractController
{

    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}


    #[Route('', name: 'app_administrateur_engin_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {

        /** @var Utilisateur $user */
        $user = $this->getUser();



        return $this->render(
            'administrateur/engin/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }

    #[Route('/ajax', name: 'app_administrateur_engin_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);
        $search  = $request->request->all('search');
        $searchV = trim((string)($search['value'] ?? ''));

        // ORDER safe (optionnel mais mieux)
        $order = $request->request->all('order');
        $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $orderDir = strtolower($order[0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $orderMap = [
            0 => 'b.id',
            1 => 'b.nom',
            2 => 's.nom',
            3 => 'b.type',
            4 => 'b.capacite',
        ];
        $orderBy = $orderMap[$orderColIdx] ?? 'b.id';

        $qb = $em->getRepository(Engin::class)->createQueryBuilder('b')
            ->leftJoin('b.site', 's')->addSelect('s')
            ->andWhere('b.entite = :entite')
            ->setParameter('entite', $entite);

        // recordsTotal (sans search)
        $recordsTotal = (int) $em->getRepository(Engin::class)->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('b.entite = :entite')
            ->setParameter('entite', $entite)
            ->getQuery()->getSingleScalarResult();

        // search
        if ($searchV !== '') {
            $qb->andWhere('b.nom LIKE :q OR s.nom LIKE :q')
                ->setParameter('q', '%' . $searchV . '%');
        }

        // recordsFiltered
        $qbCount = (clone $qb);
        $qbCount->resetDQLPart('select');
        $qbCount->resetDQLPart('orderBy');
        $recordsFiltered = (int) $qbCount->select('COUNT(DISTINCT b.id)')->getQuery()->getSingleScalarResult();

        /** @var Engin[] $rows */
        $rows = $qb
            ->orderBy($orderBy, $orderDir)
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = array_map(function (Engin $b) use ($entite) {
            $sessionsCount = method_exists($b, 'getSessions') ? $b->getSessions()->count() : '—';

            return [
                'id'         => $b->getId(),
                'nom'        => $b->getNom(),
                'site'       => $b->getSite()?->getNom() ?? '—',
                'type'       => $b->getType()?->value ?? '—',
                'capacite'   => $b->getCapacite() ?? '—',
                'couchage'   => method_exists($b, 'getCapaciteCouchage') ? ($b->getCapaciteCouchage() ?? '—') : '—',
                'cabine'     => method_exists($b, 'getCabine') ? ($b->getCabine() ?? '—') : '—',
                'douche'     => method_exists($b, 'getDouche') ? ($b->getDouche() ?? '—') : '—',
                'nbSessions' => $sessionsCount,
                'actions'    => $this->renderView('administrateur/engin/_actions.html.twig', [
                    'engin'  => $b,
                    'entite' => $entite,
                ]),
            ];
        }, $rows);

        return new JsonResponse([
            'draw'            => (int)$request->request->get('draw'),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }






    #[Route('/ajouter', name: 'app_administrateur_engin_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_engin_modifier', methods: ['GET', 'POST'])]
    public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Engin $engin = null): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();

        $isEdit = (bool) $engin;
        if (!$engin) {
            $engin = new Engin();
            $engin->setCreateur($user);
            $engin->setEntite($entite);
        }

        $form = $this->createForm(EnginType::class, $engin);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {





            // Dossier d’upload (configuré en paramètre)
            $uploadPath = $this->getParameter('engin_upload_dir');




            // 1) Photo de couverture (vignette pour la liste) — redimensionnée 360x240
            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photoCouverture',
                setter: fn(string $name) => $engin->setPhotoCouverture($name), // ✅ setter
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 1600,
                sizeH: 600,
                oldFilename: $engin->getPhotoCouverture() // ⚠️ à lire AVANT set
            );


            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photoBanniere',
                setter: fn(string $name) => $engin->setPhotoBanniere($name), // ✅ setter
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 360,
                sizeH: 240,
                oldFilename: $engin->getPhotoBanniere() // ⚠️ à lire AVANT set
            );


            // 2) Galerie — on ajoute les nouvelles images, redimensionnées 1600x900
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile[]|null $galleryFiles */
            $galleryFiles = $form->get('galleryFiles')->getData();
            if ($galleryFiles) {
                $pos = $engin->getPhotos()->count();
                $imagine = new Imagine();
                foreach ($galleryFiles as $file) {
                    $filename = $this->fileUploader->upload($file, $uploadPath);

                    // Redimensionnement paysage agréable pour le plein écran
                    $imagine->open($uploadPath . '/' . $filename)
                        ->thumbnail(new Box(1600, 900), ImageInterface::THUMBNAIL_OUTBOUND)
                        ->save($uploadPath . '/' . $filename);

                    $photo = (new EnginPhoto())
                        ->setFilename($filename)
                        ->setEntite($entite)
                        ->setCreateur($user)
                        ->setPosition($pos++);
                    $engin->addPhoto($photo);
                }
            }




            $em->persist($engin);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Engin modifié.' : 'Engin ajouté.');
            return $this->redirectToRoute('app_administrateur_engin_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/engin/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'engin'      => $engin,
            'entite' => $entite,
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_engin_supprimer', methods: ['POST'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Engin $engin, Request $request): RedirectResponse
    {


        // 🔒 cloisonnement entité
        if ($engin->getEntite()?->getId() !== $entite->getId()) {
            throw $this->createNotFoundException();
        }

        // ✅ CSRF (doit matcher le twig)
        $token = (string)$request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_engin_' . $engin->getId(), $token)) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_administrateur_engin_index', ['entite' => $entite->getId()]);
        }

        $id = $engin->getId();
        $em->remove($engin);
        $em->flush();

        $this->addFlash('success', 'Engin #' . $id . ' supprimé.');
        return $this->redirectToRoute('app_administrateur_engin_index', ['entite' => $entite->getId()]);
    }



    #[Route('/{id}/photos/reorder', name: 'app_administrateur_engin_photos_reorder', methods: ['POST'])]
    public function reorderPhotos(
        Entite $entite,
        Engin $engin,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {


        $payload = json_decode($request->getContent() ?: '[]', true);
        $order   = $payload['order'] ?? null; // attendu: [photoId1, photoId2, ...]

        if (!\is_array($order) || empty($order)) {
            return new JsonResponse(['success' => false, 'message' => 'Ordre invalide'], 400);
        }

        // Map de sécurité : on ne touche qu’aux photos de CETTE engin
        $photosById = [];
        foreach ($engin->getPhotos() as $p) {
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



    #[Route('/photo/{id}/supprimer', name: 'app_administrateur_engin_photo_supprimer', methods: ['POST'])]
    public function deletePhoto(
        Entite $entite,
        EnginPhoto $photo,
        EntityManagerInterface $em,
        Request $request
    ): Response {


        // (Optionnel) CSRF :
        // if (!$this->isCsrfTokenValid('del_photo_'.$photo->getId(), $request->request->get('_token'))) {
        //     throw $this->createAccessDeniedException('CSRF invalide');
        // }

        // Supprimer le fichier physique
        $uploadPath = rtrim($this->getParameter('engin_upload_dir'), '/');
        $filepath = $uploadPath . '/' . $photo->getFilename();
        if (is_file($filepath)) @unlink($filepath);

        // Supprimer l’entité
        $engin = $photo->getEngin();
        $em->remove($photo);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true]);
        }

        $this->addFlash('success', 'Photo supprimée.');
        // Redirige vers l’édition de la engin (plus pratique)
        return $this->redirectToRoute('app_administrateur_engin_modifier', [
            'entite' => $entite->getId(),
            'id'     => $engin?->getId(),
        ]);
    }
}
