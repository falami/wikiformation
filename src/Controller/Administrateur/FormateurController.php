<?php

namespace App\Controller\Administrateur;

use App\Entity\{Formateur, Entite, Utilisateur, Site, Engin};
use App\Service\FileUploader;
use App\Service\Photo\PhotoManager;
use App\Form\Administrateur\FormateurType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/formateur')]
#[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
final class FormateurController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
        private PhotoManager $photoManager,
        private FileUploader $fileUploader,
    ) {}
    #[Route('', name: 'app_administrateur_formateur_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render(
            'administrateur/formateur/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }


    #[Route('/meta', name: 'app_administrateur_formateur_meta', methods: ['GET'])]
    public function meta(Entite $entite, EntityManagerInterface $em): JsonResponse
    {


        // Engins appartenant à l'entité via site -> entite
        $engins = $em->createQueryBuilder()
            ->select('e.id, e.nom')
            ->from(Engin::class, 'e')
            ->innerJoin('e.site', 'es')
            ->andWhere('es.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('e.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // Sites appartenant à l'entité
        $sites = $em->createQueryBuilder()
            ->select('s.id, s.nom')
            ->from(Site::class, 's')
            ->andWhere('s.entite = :entite')
            ->setParameter('entite', $entite)
            ->orderBy('s.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return new JsonResponse([
            'engins' => array_map(fn($e) => ['id' => (int)$e['id'], 'label' => $e['nom']], $engins),
            'sites'  => array_map(fn($s) => ['id' => (int)$s['id'], 'label' => $s['nom']], $sites),
        ]);
    }

    #[Route('/ajax', name: 'app_administrateur_formateur_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);

        $search  = $request->request->all('search');
        $searchV = trim((string)($search['value'] ?? ''));

        $enginFilter = (string)$request->request->get('enginFilter', 'all');
        $siteFilter  = (string)$request->request->get('siteFilter', 'all');

        // ordering DataTables
        $order = $request->request->all('order');
        $columnsReq = $request->request->all('columns');
        $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $orderDir = strtolower($order[0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        // mapping index colonne -> champ DQL autorisé
        // 0:id, 1:nom(fullname), 2:certifications, 3/4 agrégats => on trie pas dessus, 5:nbSessions => on trie pas ici (sinon subquery)
        $orderMap = [
            0 => 'sk.id',
            1 => 'u.nom',            // tri approximatif: nom
            2 => 'sk.certifications',
        ];
        $orderBy = $orderMap[$orderColIdx] ?? 'sk.id';

        $qb = $em->getRepository(Formateur::class)->createQueryBuilder('sk')
            ->leftJoin('sk.utilisateur', 'u')->addSelect('u')
            ->leftJoin('sk.qualificationEngins', 'e')->addSelect('e')
            ->leftJoin('sk.sitePreferes', 's')->addSelect('s')
            ->andWhere('sk.entite = :entite')
            ->setParameter('entite', $entite);

        // Filtres Engin / Site (vrais filtres)
        if ($enginFilter !== 'all' && ctype_digit($enginFilter)) {
            $enginEntity = $em->getRepository(Engin::class)->find((int)$enginFilter);
            if ($enginEntity) {
                $qb->andWhere(':engin MEMBER OF sk.qualificationEngins')
                    ->setParameter('engin', $enginEntity);
            }
        }
        if ($siteFilter !== 'all' && ctype_digit($siteFilter)) {
            $siteEntity = $em->getRepository(Site::class)->find((int)$siteFilter);
            if ($siteEntity) {
                $qb->andWhere(':site MEMBER OF sk.sitePreferes')
                    ->setParameter('site', $siteEntity);
            }
        }

        // Total non filtré (après filtre entité uniquement)
        $qbTotal = $em->getRepository(Formateur::class)->createQueryBuilder('sk')
            ->select('COUNT(DISTINCT sk.id)')
            ->andWhere('sk.entite = :entite')
            ->setParameter('entite', $entite);

        $recordsTotal = (int)$qbTotal->getQuery()->getSingleScalarResult();

        // Recherche globale
        if ($searchV !== '') {
            $qb->andWhere('
            u.nom LIKE :q
            OR u.prenom LIKE :q
            OR sk.certifications LIKE :q
            OR e.nom LIKE :q
            OR s.nom LIKE :q
        ')->setParameter('q', '%' . $searchV . '%');
        }

        // Total filtré (après filtres + search)
        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT sk.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        /** @var Formateur[] $rows */
        $rows = $qb
            ->orderBy($orderBy, $orderDir)
            ->addOrderBy('sk.id', 'DESC')
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()
            ->getResult();

        $data = array_map(function (Formateur $sk) use ($entite) {
            $u = $sk->getUtilisateur();
            $fullname = trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? ''));

            // Engins qualifiés
            $engins = [];
            foreach ($sk->getQualificationEngins() as $e) {
                $engins[] = $e->getNom();
            }
            $enginsStr = $engins ? implode(', ', $engins) : '—';

            // Sites préférés
            $sites = [];
            foreach ($sk->getSitePreferes() as $s) {
                $sites[] = $s->getNom();
            }
            $sitesStr = $sites ? implode(', ', $sites) : '—';

            // Sessions
            $nbSessions = $sk->getSessions()->count();

            return [
                'id'               => $sk->getId(),
                'nom'              => $fullname !== '' ? $fullname : '—',
                'certifications'   => $sk->getCertifications() ?: '—',
                'enginsQualifies'  => $enginsStr,
                'sitesPreferes'    => $sitesStr,
                'nbSessions'       => $nbSessions,
                'actions'          => $this->renderView('administrateur/formateur/_actions.html.twig', [
                    'formateur' => $sk,
                    'entite'    => $entite,
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



    #[Route('/ajouter', name: 'app_administrateur_formateur_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_formateur_modifier', methods: ['GET', 'POST'])]
    public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Formateur $formateur = null): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool) $formateur;
        if (!$formateur) {
            $formateur = new Formateur();
            $formateur->setCreateur($user);
            $formateur->setEntite($entite);
        }

        $form = $this->createForm(FormateurType::class, $formateur, [
            'entite' => $entite,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {



            // Dossier d’upload (configuré en paramètre)
            $uploadPath = $this->getParameter('formateur_upload_dir');

            // 1) Photo de couverture (vignette pour la liste) — redimensionnée 360x240
            $oldPhoto = $formateur->getPhoto();

            $this->photoManager->handleImageUpload(
                form: $form,
                fieldName: 'photo',
                setter: fn(string $name) => $formateur->setPhoto($name),
                fileUploader: $this->fileUploader,
                uploadPath: $uploadPath,
                sizeW: 500,
                sizeH: 500,
                oldFilename: $oldPhoto
            );


            $em->persist($formateur);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Formateur modifié.' : 'Formateur ajouté.');
            return $this->redirectToRoute('app_administrateur_formateur_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/formateur/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'formateur'     => $formateur,
            'entite' => $entite,
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_formateur_supprimer', methods: ['GET'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Formateur $formateur): RedirectResponse
    {


        $id = $formateur->getId();
        $em->remove($formateur);
        $em->flush();

        $this->addFlash('success', 'Formateur #' . $id . ' supprimé.');
        return $this->redirectToRoute('app_administrateur_formateur_index', [
            'entite' => $entite->getId(),
        ]);
    }
}
