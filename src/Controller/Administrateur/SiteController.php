<?php

namespace App\Controller\Administrateur;

use App\Entity\{Site, Entite, Utilisateur};
use App\Service\Email\MailerManager;
use App\Form\Administrateur\SiteType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/site')]
#[IsGranted(TenantPermission::SITE_MANAGE, subject: 'entite')]
final class SiteController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
    ) {}

    #[Route('', name: 'app_administrateur_site_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render(
            'administrateur/site/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }

    #[Route('/ajax', name: 'app_administrateur_site_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);
        $search  = $request->request->all('search');
        $searchV = $search['value'] ?? '';

        $repo = $em->getRepository(Site::class);

        // --- Totaux (non filtré) ---
        $qbTotal = $repo->createQueryBuilder('s');
        $recordsTotal = (int)$qbTotal
            ->select('COUNT(s.id)')
            ->andWhere('s.entite = :entite') // <-- adapte ici si besoin
            ->setParameter('entite', $entite)
            ->getQuery()->getSingleScalarResult();

        // --- Filtre texte ---
        $qbFiltered = $repo->createQueryBuilder('s');
        if ($searchV) {
            $qbFiltered->andWhere('s.nom LIKE :s OR s.slug LIKE :s OR s.ville LIKE :s OR s.region LIKE :s OR s.pays LIKE :s')
                ->setParameter('s', '%' . $searchV . '%');
        }
        $recordsFiltered = (int)$qbFiltered
            ->select('COUNT(s.id)')
            ->andWhere('s.entite = :entite') // <-- adapte ici si besoin
            ->setParameter('entite', $entite)
            ->getQuery()->getSingleScalarResult();

        // --- Lignes (avec counts) ---
        $qbRows = $repo->createQueryBuilder('s')
            ->leftJoin('s.engins', 'b')
            ->leftJoin('s.sessions', 'se')

            ->andWhere('s.entite = :entite') // <-- adapte ici si besoin
            ->setParameter('entite', $entite)
            // On renvoie à la fois l'entité et les deux compteurs
            ->addSelect('COUNT(DISTINCT b.id) AS nbEngins')
            ->addSelect('COUNT(DISTINCT se.id) AS nbSessions');

        if ($searchV) {
            $qbRows->andWhere('s.nom LIKE :s OR s.slug LIKE :s OR s.ville LIKE :s OR s.region LIKE :s OR s.pays LIKE :s')
                ->setParameter('s', '%' . $searchV . '%');
        }

        $rows = $qbRows
            ->groupBy('s.id')
            ->orderBy('s.id', 'DESC')
            ->andWhere('s.entite = :entite') // <-- adapte ici si besoin
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->setParameter('entite', $entite)
            ->getQuery()->getResult(); // tableau: [0 => Site, 'nbEngins' => int, 'nbSessions' => int]

        $data = array_map(function ($row) use ($entite) {
            /** @var Site $s */
            $s = is_array($row) ? $row[0] : $row;
            $nbEngins  = is_array($row) ? (int)$row['nbEngins']  : $s->getEngins()->count();
            $nbSessions = is_array($row) ? (int)$row['nbSessions'] : $s->getSessions()->count();

            $coords = ($s->getLatitude() !== null && $s->getLongitude() !== null)
                ? ($s->getLatitude() . ', ' . $s->getLongitude())
                : '—';

            return [
                'id'          => $s->getId(),
                'nom'         => $s->getNom(),
                'ville'       => $s->getVille() ?? '',
                'region'      => $s->getRegion() ?? '',
                'pays'        => $s->getPays() ?? '',
                'coords'      => $coords,
                'nbEngins'   => $nbEngins,
                'nbSessions'  => $nbSessions,
                'actions'     => $this->renderView('administrateur/site/_actions.html.twig', [
                    'site'   => $s,
                    'entite' => $entite, // ✅ nécessaire au partial
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


    #[Route('/ajouter', name: 'app_administrateur_site_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_site_modifier', methods: ['GET', 'POST'])]
    public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Site $site = null): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool) $site;
        if (!$site) {
            $site = new Site();
            $site->setCreateur($user);
            $site->setEntite($entite);
        }

        $form = $this->createForm(SiteType::class, $site);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($site);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Site modifié.' : 'Site ajouté.');
            return $this->redirectToRoute('app_administrateur_site_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/site/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'site'        => $site,
            'entite'      => $entite,

            'googleMapsBrowserKey' => $this->getParameter('GOOGLE_MAPS_BROWSER_KEY'),
        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_site_supprimer', methods: ['GET'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Site $site): RedirectResponse
    {


        $id = $site->getId();
        $em->remove($site);
        $em->flush();

        $this->addFlash('success', 'Site #' . $id . ' supprimé.');
        return $this->redirectToRoute('app_administrateur_site_index', [
            'entite' => $entite->getId(),
        ]);
    }
}
