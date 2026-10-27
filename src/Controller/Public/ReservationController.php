<?php
// src/Controller/Public/ReservationController.php
namespace App\Controller\Public;

use App\Entity\{Reservation, Formation, Session, Utilisateur};
use App\Enum\StatusReservation;
use App\Enum\StatusSession;
use App\Form\Public\ReservationType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Public\PublicContext;

#[Route('/formation', name: 'app_public_reservation_')]
class ReservationController extends AbstractController
{
    public function __construct(
        private EM $em,
        private readonly PublicContext $publicContext,
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/{slug}/reservation', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $slug, Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }

        // Slugs are unique per organism, not globally. Resolve only a formation
        // actually exposed on this host before showing or collecting any data.
        $candidates = $this->em->getRepository(Formation::class)
            ->findBy(['slug' => $slug, 'isPublic' => true], ['id' => 'ASC']);
        $formation = null;
        foreach ($candidates as $candidate) {
            if ($this->publicContext->allowsFormation($candidate)) {
                $formation = $candidate;
                break;
            }
        }

        if (!$formation || !$formation->getEntite()) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        $reservation = new Reservation();
        $reservation->setFormation($formation);
        $reservation->setUtilisateur($user);
        $reservation->setDateReservation(new \DateTimeImmutable());

        $reservation->setCreateur($user);
        $reservation->setEntite($formation->getEntite());

        // Si un ID de session est passé, on lie la session
        $session = null;
        $sessionId = $request->query->getInt('session', 0);
        if ($request->query->has('session')) {
            $session = $this->em->getRepository(Session::class)->find($sessionId);

            if ($sessionId > 0 && $session
                && $session->getFormation()?->getId() === $formation->getId()
                && $session->getEntite()?->getId() === $formation->getEntite()->getId()
                && $session->getStatus() === StatusSession::PUBLISHED
            ) {
                $reservation->setSession($session);
                $reservation->setStatus(StatusReservation::PENDING); // pré-réservation sur une session existante
                // montant "théorique" = tarif de la session ou prix formation
                $montant = $session->getMontantCents() ?? $formation->getPrixBaseCents();
                $reservation->setMontantCents($montant ?? 0);
            } else {
                throw $this->createNotFoundException('Session introuvable.');
            }
        } else {
            // Pas de session : demande d’ouverture de session
            $reservation->setStatus(StatusReservation::WAITING_LIST);
            // montantCents peut rester à 0, ce sera défini plus tard
        }

        $form = $this->createForm(ReservationType::class, $reservation);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // TODO: contrôler la capacité si session définie
            // ex: vérifier que places <= (session.capacite - session.reservations.count - inscriptions.count)

            $this->em->persist($reservation);
            $this->em->flush();

            // TODO: envoyer un mail au responsable / OF si tu veux

            if ($reservation->getSession()) {
                $this->addFlash('success', 'Votre pré-réservation a bien été enregistrée. Vous pourrez finaliser l’inscription plus tard.');
            } else {
                $this->addFlash('success', 'Votre demande d’ouverture de session a bien été enregistrée. Nous vous recontacterons rapidement.');
            }

            return $this->redirectToRoute('app_public_show', [
                'slug' => $formation->getSlug(),
            ]);
        }

        $response = $this->render('public/reservation/new.html.twig', [
            'formation' => $formation,
            'form'      => $form->createView(),
            'session'   => $session,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
