<?php
// src/Controller/Public/ReservationController.php
namespace App\Controller\Public;

use App\Entity\{Reservation, Formation, Session, Utilisateur};
use App\Enum\StatusReservation;
use App\Form\Public\ReservationType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\Utilisateur\UtilisateurManager;

#[Route('/formation', name: 'app_public_reservation_')]
class ReservationController extends AbstractController
{
    public function __construct(
        private EM $em,
        private readonly UtilisateurManager $utilisateurManager,
    ) {}

    #[IsGranted('ROLE_USER')]
    #[Route('/{slug}/reservation', name: 'new', methods: ['GET', 'POST'])]
    public function new(string $slug, Request $request): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $formation = $this->em->getRepository(Formation::class)
            ->findOneBy(['slug' => $slug]);

        if (!$formation) {
            throw $this->createNotFoundException('Formation introuvable.');
        }

        $reservation = new Reservation();
        $reservation->setFormation($formation);
        $reservation->setUtilisateur($user);
        $reservation->setDateReservation(new \DateTimeImmutable());

        if ($user !== null) {
            $reservation->setCreateur($user);
            $reservation->setEntite($user->getEntite());
        } else {
            $user = $this->utilisateurManager->getRepository()->findOneBy(['id' => 1]);
            $reservation->setCreateur($user);
            $reservation->setEntite($user->getEntite());
        }

        // Si un ID de session est passé, on lie la session
        $sessionId = $request->query->getInt('session', 0);
        if ($sessionId > 0) {
            $session = $this->em->getRepository(Session::class)->find($sessionId);

            if ($session && $session->getFormation() === $formation) {
                $reservation->setSession($session);
                $reservation->setStatus(StatusReservation::PENDING); // pré-réservation sur une session existante
                // montant "théorique" = tarif de la session ou prix formation
                $montant = $session->getMontantCents() ?? $formation->getPrixBaseCents();
                $reservation->setMontantCents($montant ?? 0);
            } else {
                // sécurité : session invalide => on ignore
                $this->addFlash('danger', 'La session sélectionnée n’est pas valide.');
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

        return $this->render('public/reservation/new.html.twig', [
            'formation' => $formation,
            'form'      => $form->createView(),
            'session'   => isset($session) ? $session : null,
        ]);
    }
}
