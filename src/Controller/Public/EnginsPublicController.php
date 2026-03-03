<?php
// src/Controller/Public/EnginsPublicController.php
declare(strict_types=1);

namespace App\Controller\Public;

use App\Enum\EnginType;
use App\Filter\EnginsFilter;
use App\Repository\EnginRepository;
use App\Repository\SiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/')]
final class EnginsPublicController extends AbstractController
{
    public function __construct(
        private readonly EnginRepository $enginRepo,
        private readonly SiteRepository   $siteRepo,
    ) {}

    #[Route('/engin', name: 'app_public_engin', methods: ['GET'])]
    public function index(Request $request): Response
    {
        // 1) Lire les filtres de la query string
        $filter = EnginsFilter::fromQuery($request->query->all());

        // 2) Récupérer les destinations (sites) qui possèdent au moins un engin
        //    (équivalent à ton findDistinctDestinationsHavingSessions)
        $destinations = $this->siteRepo->createQueryBuilder('s')
            ->innerJoin('s.engins', 'b')
            ->groupBy('s.id')
            ->orderBy('s.nom', 'ASC')
            ->getQuery()->getResult();

        // 3) Chercher les engins selon filtres
        $qb = $this->enginRepo->createQueryBuilder('b')
            ->leftJoin('b.site', 'site')->addSelect('site')
            ->leftJoin('b.formateurQualifications', 'sk')->addSelect('sk');

        if ($filter->destinationId) {
            $qb->andWhere('site.id = :sid')->setParameter('sid', $filter->destinationId);
        }
        if ($filter->types) {
            // $filter->types contient les backed values de l'enum EnginType
            $qb->andWhere('b.type IN (:types)')->setParameter('types', $filter->types);
        }
        if ($filter->capMin) {
            $qb->andWhere('b.capacite >= :cap')->setParameter('cap', $filter->capMin);
        }
        if ($filter->couchMin) {
            $qb->andWhere('b.capaciteCouchage >= :couch')->setParameter('couch', $filter->couchMin);
        }

        $engins = $qb->orderBy('b.nom', 'ASC')->setMaxResults(300)->getQuery()->getResult();

        // 4) Préparer les choix d’enum pour Twig (backed values)
        $typeChoices = array_map(static fn($c) => $c->value, EnginType::cases());

        // 5) Rendu (page complète ou fragment AJAX)
        $tpl = $request->isXmlHttpRequest()
            ? 'public/engin/_list.html.twig'   // contient #results et #results-count
            : 'public/engin/index.html.twig';  // ta page complète “flotte”

        return $this->render($tpl, [
            'engins'       => $engins,
            'sites'         => $destinations,
            'activeFilters' => $filter->toActiveFilters(),
            'typeChoices'   => $typeChoices,
        ]);
    }

    #[Route('/engin/{id}', name: 'app_public_engin_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        // Eager loading simple : site + formateurs + (éventuelles photos si mappées en EAGER sinon join)
        $qb = $this->enginRepo->createQueryBuilder('b')
            ->leftJoin('b.site', 's')->addSelect('s')
            ->leftJoin('b.formateurQualifications', 'sk')->addSelect('sk')
            ->andWhere('b.id = :id')->setParameter('id', $id)
            ->setMaxResults(1);

        $engin = $qb->getQuery()->getOneOrNullResult();

        if (!$engin) {
            throw $this->createNotFoundException('Engin introuvable.');
        }

        // Meta basiques (adapte selon ta page show)
        $metaTitle = $engin->getNom() . ' — Notre flotte';
        $metaDesc  = sprintf(
            'Découvrez %s (%s) basé à %s. Capacité %s pers., %s couchages.',
            $engin->getNom(),
            $engin->getType()->value ?? 'Engin',
            $engin->getSite()?->getNom() ?? '—',
            $engin->getCapacite() ?? '—',
            $engin->getCapaciteCouchage() ?? '—'
        );

        return $this->render('public/engin/show.html.twig', [
            'engin'    => $engin,
            'metaTitle' => $metaTitle,
            'metaDesc'  => $metaDesc,
        ]);
    }
}
