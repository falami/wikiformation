<?php

namespace App\Controller\Administrateur;

use App\Form\Administrateur\ReservationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Enum\StatusReservation;
use App\Enum\StatusInscription;
use App\Entity\{Session, Entite, Utilisateur, Reservation, Inscription};
use App\Service\Email\MailerManager;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;



#[Route('/administrateur/{entite}/reservation')]
#[IsGranted(TenantPermission::RESERVATION_MANAGE, subject: 'entite')]
final class ReservationController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private MailerManager $mailerManager,
    ) {}
    #[Route('', name: 'app_administrateur_reservation_index', methods: ['GET'])]
    public function index(Entite $entite): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        return $this->render(
            'administrateur/reservation/index.html.twig',
            [
                'entite' => $entite,

            ]
        );
    }

    #[Route('/ajax', name: 'app_administrateur_reservation_ajax', methods: ['POST'])]
    public function ajax(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        try {
            $draw   = $request->request->getInt('draw', 1);
            $start  = $request->request->getInt('start', 0);
            $length = $request->request->getInt('length', 10);

            $search  = $request->request->all('search');
            $searchV = trim((string)($search['value'] ?? ''));

            $statusFilter = (string) $request->request->get('statusFilter', 'all');

            $repo = $em->getRepository(Reservation::class);

            $baseQb = $repo->createQueryBuilder('r')
                ->leftJoin('r.session', 's')->addSelect('s')
                ->leftJoin('r.formation', 'f')->addSelect('f')  // ✅ pas s.formation
                ->leftJoin('r.utilisateur', 'u')->addSelect('u')
                ->andWhere('r.entite = :entite')               // ✅ pas s.entite
                ->setParameter('entite', $entite);

            $recordsTotal = (int) (clone $baseQb)
                ->select('COUNT(r.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $filteredQb = clone $baseQb;

            if ($searchV !== '') {
                $filteredQb
                    ->andWhere('(s.code LIKE :q OR f.titre LIKE :q OR u.nom LIKE :q OR u.prenom LIKE :q)')
                    ->setParameter('q', '%' . $searchV . '%');
            }

            if ($statusFilter !== 'all') {
                $st = StatusReservation::tryFrom($statusFilter);
                if ($st) {
                    $filteredQb->andWhere('r.status = :st')->setParameter('st', $st);
                }
            }

            $recordsFiltered = (int) (clone $filteredQb)
                ->select('COUNT(r.id)')
                ->getQuery()
                ->getSingleScalarResult();

            $rows = $filteredQb
                ->orderBy('r.id', 'DESC')
                ->setFirstResult($start)
                ->setMaxResults($length)
                ->getQuery()
                ->getResult();

            $data = array_map(function (Reservation $r) use ($entite) {
                $u = $r->getUtilisateur();
                $s = $r->getSession();

                $fullName = trim(($u?->getPrenom() ?? '') . ' ' . ($u?->getNom() ?? '')) ?: '-';


                $formationTitle = $r->getFormation()?->getTitre()
                    ?? $s?->getFormation()?->getTitre()
                    ?? '-';

                $sessionStr = ($s?->getCode() ?? 'Demande d’ouverture')
                    . ' - ' . $formationTitle;


                $dateStr    = $r->getDateReservation()?->format('d/m/Y') ?? '-';
                $amount = ($r->getMontantCents() ?? 0) / 100;
                $currency = strtoupper($r->getDevise() ?: 'EUR');

                $symbol = match ($currency) {
                    'EUR' => '€',
                    'USD' => '$',
                    'GBP' => '£',
                    'CHF' => 'CHF',
                    default => $currency, // fallback si devise inconnue
                };

                $montantStr = number_format($amount, 2, ',', ' ') . ' ' . $symbol;


                $statusHtml = $this->renderStatusBadge($r->getStatus());

                // canConfirm (comme tu l’avais)
                $canConfirm = false;
                if ($s && $u && in_array($r->getStatus(), [StatusReservation::PENDING, StatusReservation::PAID], true)) {
                    $alreadyInscrit = false;
                    foreach ($s->getInscriptions() as $inscription) {
                        if (
                            $inscription->getStagiaire() === $u &&
                            !in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)
                        ) {
                            $alreadyInscrit = true;
                            break;
                        }
                    }
                    $canConfirm = !$alreadyInscrit;
                }
                if ($r->getPlaces() > 1)
                    $places = $r->getPlaces() . " places";
                else
                    $places = $r->getPlaces() . " place";
                return [
                    'id'            => $r->getId(),
                    'session'       => $sessionStr,
                    'utilisateur'   => $fullName,
                    'place'         => $places,
                    'dateReservation' => $dateStr,
                    'montant'       => $montantStr,
                    'status'        => $statusHtml,
                    'actions'       => $this->renderView('administrateur/reservation/_actions.html.twig', [
                        'reservation' => $r,
                        'entite'      => $entite,
                        'canConfirm'  => $canConfirm,
                    ]),
                ];
            }, $rows);

            return new JsonResponse([
                'draw'            => $draw,
                'recordsTotal'    => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data'            => $data,
            ]);
        } catch (\Throwable $e) {
            // ✅ DataTables comprendra et tu verras l’erreur
            return new JsonResponse([
                'draw' => (int) $request->request->get('draw', 1),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'error' => $e->getMessage(),
            ], 500);
        }
    }




    #[Route('/kpis', name: 'app_administrateur_reservation_kpis', methods: ['GET'])]
    public function kpis(Entite $entite, Request $request, EntityManagerInterface $em): JsonResponse
    {


        $status = (string) $request->query->get('status', 'all');

        $qb = $em->getRepository(Reservation::class)->createQueryBuilder('r');

        $qb->andWhere('r.entite = :entite')
            ->setParameter('entite', $entite);

        if ($status !== 'all') {
            try {
                $st = StatusReservation::from($status);
                $qb->andWhere('r.status = :st')->setParameter('st', $st);
            } catch (\ValueError $e) {
            }
        }

        $count = (int)(clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $amountCents = (int)(clone $qb)->select('COALESCE(SUM(r.montantCents),0)')->getQuery()->getSingleScalarResult();

        $pending = (int)(clone $qb)->andWhere('r.status = :p')->setParameter('p', StatusReservation::PENDING)
            ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $confirmed = (int)(clone $qb)->andWhere('r.status = :c')->setParameter('c', StatusReservation::CONFIRMED)
            ->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        return new JsonResponse([
            'count' => $count,
            'amountCents' => $amountCents,
            'pending' => $pending,
            'confirmed' => $confirmed,
        ]);
    }



    private function renderStatusBadge(?StatusReservation $st): string
    {
        if (!$st) {
            return '<span class="badge text-bg-secondary">-</span>';
        }

        $class = match ($st) {
            StatusReservation::PENDING      => 'text-bg-warning',
            StatusReservation::PAID         => 'text-bg-success',
            StatusReservation::CONFIRMED    => 'text-bg-primary',
            StatusReservation::WAITING_LIST => 'text-bg-info',
            StatusReservation::CANCELED     => 'text-bg-dark',
            StatusReservation::REFUNDED     => 'text-bg-secondary',
            default                         => 'text-bg-secondary', // ✅ évite le crash
        };

        // si label() existe
        $label = method_exists($st, 'label') ? $st->label() : $st->value;
        $label = htmlspecialchars((string) $label, ENT_QUOTES);

        return sprintf('<span class="badge %s">%s</span>', $class, $label);
    }







    #[Route('/ajouter', name: 'app_administrateur_reservation_ajouter', methods: ['GET', 'POST'])]
    #[Route('/modifier/{id}', name: 'app_administrateur_reservation_modifier', methods: ['GET', 'POST'])]
    public function addEdit(Entite $entite, Request $request, EntityManagerInterface $em, ?Reservation $reservation = null): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool) $reservation;
        if (!$reservation) {
            $reservation = new Reservation();
            $reservation->setCreateur($user);
            $reservation->setEntite($entite);
            $reservation->setDateReservation(new \DateTimeImmutable());
        }

        $form = $this->createForm(ReservationType::class, $reservation, [
            'entite' => $entite,
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($reservation);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Réservation modifiée.' : 'Réservation ajoutée.');
            return $this->redirectToRoute('app_administrateur_reservation_index', [
                'entite' => $entite->getId(),
            ]);
        }

        return $this->render('administrateur/reservation/form.html.twig', [
            'form'        => $form->createView(),
            'modeEdition' => $isEdit,
            'reservation' => $reservation,
            'entite' => $entite,

        ]);
    }

    #[Route('/supprimer/{id}', name: 'app_administrateur_reservation_supprimer', methods: ['GET'])]
    public function delete(Entite $entite, EntityManagerInterface $em, Reservation $reservation): RedirectResponse
    {


        $id = $reservation->getId();
        $em->remove($reservation);
        $em->flush();

        $this->addFlash('success', 'Réservation #' . $id . ' supprimée.');
        return $this->redirectToRoute('app_administrateur_reservation_index', [
            'entite' => $entite->getId(),
        ]);
    }


    private function getPlacesDisponibles(Session $session): int
    {
        $capacite = $session->getCapacite();

        // Inscriptions déjà actives (hors ANNULE / ABSENT si tu veux)
        $inscriptionsConfirmées = 0;
        foreach ($session->getInscriptions() as $inscription) {
            if (!in_array($inscription->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)) {
                $inscriptionsConfirmées++;
            }
        }

        // Réservations encore actives (hors CANCELED si tu as ce statut)
        $reservationsActives = 0;
        foreach ($session->getReservations() as $resa) {
            if (!in_array($resa->getStatus(), [StatusReservation::CANCELED], true)) {
                $reservationsActives += $resa->getPlaces() ?? 1;
            }
        }

        // Capacité - (inscriptions + réservations)
        return max(0, $capacite - $inscriptionsConfirmées - $reservationsActives);
    }


    #[Route('/confirmer/{id}/ajax', name: 'app_administrateur_reservation_confirmer_ajax', methods: ['POST'])]
    public function confirmerAjax(Entite $entite, Reservation $reservation, EntityManagerInterface $em): JsonResponse
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        // 👉 on réutilise ta logique existante, mais au lieu de redirect => JSON
        $session = $reservation->getSession();
        if (!$session) {
            return new JsonResponse(['ok' => false, 'message' => "Aucune session associée."], 400);
        }

        $placesDemandees = $reservation->getPlaces() ?? 1;
        $placesDispo = $this->getPlacesDisponibles($session);

        if ($placesDemandees > $placesDispo) {
            return new JsonResponse([
                'ok' => false,
                'message' => sprintf("Seulement %d place(s) disponible(s).", $placesDispo)
            ], 400);
        }

        // Déjà inscrit ?
        foreach ($session->getInscriptions() as $ins) {
            if (
                $ins->getStagiaire() === $reservation->getUtilisateur()
                && !in_array($ins->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)
            ) {
                return new JsonResponse(['ok' => false, 'message' => "Déjà inscrit sur cette session."], 400);
            }
        }

        $inscription = new Inscription();
        $inscription->setCreateur($user);
        $inscription->setEntite($entite);
        $inscription->setSession($session);
        $inscription->setStagiaire($reservation->getUtilisateur());
        $inscription->setMontantDuCents($reservation->getMontantCents());

        if ($reservation->getStatus() === StatusReservation::PAID) {
            $inscription->setMontantRegleCents($reservation->getMontantCents());
            $inscription->setStatus(StatusInscription::CONFIRME);
        } else {
            $inscription->setMontantRegleCents(0);
            $inscription->setStatus(StatusInscription::PREINSCRIT);
        }

        $meta = $inscription->getMeta() ?? [];
        $meta['reservation_id'] = $reservation->getId();
        $meta['reservation_places'] = $reservation->getPlaces();
        $inscription->setMeta($meta);

        $em->persist($inscription);

        $reservation->setStatus(StatusReservation::CONFIRMED);
        $em->persist($reservation);

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }
}
