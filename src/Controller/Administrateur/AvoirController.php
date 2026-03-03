<?php

namespace App\Controller\Administrateur;

use App\Entity\{Avoir, Entite, Utilisateur};
use App\Form\Administrateur\AvoirType;
use App\Service\Sequence\AvoirNumberGenerator;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/avoir', name: 'app_administrateur_avoir_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::AVOIR_MANAGE, subject: 'entite')]
class AvoirController extends AbstractController
{
    public function __construct(
        private AvoirNumberGenerator $avoirNumberGenerator,
    ) {}

    #[Route('/avoirs', name: 'index', methods: ['GET'])]
    public function avoirs(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render('administrateur/avoir/index.html.twig', [
            'entite' => $entite,
        ]);
    }

    #[Route('/ajax', name: 'ajax', methods: ['POST'])]
    public function avoirsAjax(Entite $entite, Request $request, EM $em): JsonResponse
    {
        $start   = $request->request->getInt('start', 0);
        $length  = $request->request->getInt('length', 10);
        $searchV = ($request->request->all('search')['value'] ?? '');
        $order   = $request->request->all('order');

        $map = [
            0 => 'a.id',
            1 => 'fo.numero',
            2 => 'a.numero',
            3 => 'a.dateEmission',
            4 => 'a.montantTtcCents',
        ];

        $qb = $em->getRepository(Avoir::class)->createQueryBuilder('a')
            ->leftJoin('a.factureOrigine', 'fo')->addSelect('fo')
            ->andWhere('a.entite = :entite')
            ->setParameter('entite', $entite);

        $recordsTotal = (int)(clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        if ($searchV) {
            $qb->andWhere('a.numero LIKE :s OR fo.numero LIKE :s')
                ->setParameter('s', '%' . $searchV . '%');
        }

        $recordsFiltered = (int)(clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()->getSingleScalarResult();

        $orderColIdx = isset($order[0]['column']) ? (int)$order[0]['column'] : 0;
        $orderDir    = (isset($order[0]['dir']) && strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
        $orderBy     = $map[$orderColIdx] ?? 'a.dateEmission';

        $rows = $qb->orderBy($orderBy, $orderDir)
            ->setFirstResult($start)
            ->setMaxResults($length)
            ->getQuery()->getResult();

        $data = array_map(function (Avoir $a) {
            return [
                'id'      => $a->getId(),
                'origine' => $a->getFactureOrigine() ? ($a->getFactureOrigine()->getNumero() ?? '-') : '-',
                'numero'  => $a->getNumeroOrNull() ?? '-',
                'date'    => $a->getDateEmission()?->format('d/m/Y') ?? '-',
                'ttc'     => $a->getMontantTtcCents() !== null ? number_format($a->getMontantTtcCents() / 100, 2, ',', ' ') . ' €' : '-',
            ];
        }, $rows);

        return new JsonResponse([
            'draw'            => $request->request->getInt('draw', 0),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function avoirNew(Entite $entite, Request $req, EM $em): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $a = new Avoir();
        $a->setEntite($entite);
        $a->setCreateur($user);
        $a->setDateEmission(new \DateTimeImmutable());

        $form = $this->createForm(AvoirType::class, $a, [
            'entite' => $entite,
        ])->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {

            // ✅ Génération du numéro d'avoir (comme facture)
            if (!$a->hasNumero()) {
                $year = (int) $a->getDateEmission()->format('Y'); // ou null si tu veux laisser le generator décider
                $a->setNumero(
                    $this->avoirNumberGenerator->nextForEntite($entite->getId(), $year)
                );
            }

            $em->persist($a);
            $em->flush();

            $this->addFlash('success', 'Avoir créé.');
            return $this->redirectToRoute('app_administrateur_avoir_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/avoir/form.html.twig', [
            'form' => $form,
            'title' => 'Nouvel avoir',
            'entite' => $entite,
        ]);
    }

    #[Route('/kpis', name: 'kpis', methods: ['GET'])]
    public function avoirsKpis(Entite $entite, EM $em): JsonResponse
    {
        $since = (new \DateTimeImmutable())->sub(new \DateInterval('P30D'));

        $ttc = (int) $em->createQueryBuilder()
            ->select('COALESCE(SUM(a.montantTtcCents),0)')
            ->from(Avoir::class, 'a')
            ->andWhere('a.entite = :e')->setParameter('e', $entite)
            ->getQuery()->getSingleScalarResult();

        $count = (int) $em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Avoir::class, 'a')
            ->andWhere('a.entite = :e')->setParameter('e', $entite)
            ->getQuery()->getSingleScalarResult();

        $last30 = $em->createQueryBuilder()
            ->select('COUNT(a2.id) AS cnt, COALESCE(SUM(a2.montantTtcCents),0) AS sumCents')
            ->from(Avoir::class, 'a2')
            ->andWhere('a2.entite = :e')->setParameter('e', $entite)
            ->andWhere('a2.dateEmission >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleResult();

        return new JsonResponse([
            'count' => $count,
            'ttcCents' => $ttc,
            'last30Count' => (int)($last30['cnt'] ?? 0),
            'last30TtcCents' => (int)($last30['sumCents'] ?? 0),
        ]);
    }
}
