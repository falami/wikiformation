<?php
// src/Controller/Public/PublicElearningCatalogueController.php
namespace App\Controller\Public;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningOrder;
use App\Entity\Elearning\ElearningOrderItem;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/{entite}/catalogue', name: 'app_public_catalogue_')]
final class PublicElearningCatalogueController extends AbstractController
{
  #[Route('/elearning', name: 'elearning', methods: ['GET'])]
  public function list(Entite $entite, EntityManagerInterface $em): Response
  {
    $courses = $em->getRepository(ElearningCourse::class)->findBy([
      'entite' => $entite,
      'isPublic' => true,
      'isPublished' => true,
    ], ['id' => 'DESC']);

    return $this->render('public/catalogue/elearning_list.html.twig', [
      'entite' => $entite,
      'courses' => $courses,
    ]);
  }

  #[Route('/elearning/{slug}', name: 'elearning_show', methods: ['GET'])]
  public function show(Entite $entite, string $slug, EntityManagerInterface $em): Response
  {
    $course = $em->getRepository(ElearningCourse::class)->findOneBy([
      'entite' => $entite,
      'slug' => $slug,
      'isPublished' => true,
    ]);
    if (!$course) throw $this->createNotFoundException();

    return $this->render('public/catalogue/elearning_show.html.twig', [
      'entite' => $entite,
      'course' => $course,
    ]);
  }

  // MVP “commande”
  #[IsGranted('ROLE_USER')]
  #[Route('/elearning/{slug}/commander', name: 'elearning_buy', methods: ['POST'])]
  public function buy(
    Entite $entite,
    string $slug,
    Request $request,
    EntityManagerInterface $em
  ): Response {
    /** @var Utilisateur $buyer */
    $buyer = $this->getUser();

    $course = $em->getRepository(ElearningCourse::class)->findOneBy([
      'entite' => $entite,
      'slug' => $slug,
      'isPublic' => true,
      'isPublished' => true
    ]);
    if (!$course) throw $this->createNotFoundException();

    // CSRF simple
    if (!$this->isCsrfTokenValid('buy_course_' . $course->getId(), (string)$request->request->get('_token'))) {
      throw $this->createAccessDeniedException('CSRF invalide.');
    }

    $order = (new ElearningOrder())
      ->setEntite($entite)
      ->setBuyer($buyer)
      ->setStatus(OrderStatus::PENDING)
      ->setReference('EL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))));

    $item = (new ElearningOrderItem())
      ->setNewOrder($order)
      ->setCourse($course)
      ->setQty(1)
      ->setUnitPriceCents($course->getPrixCents())
      ->setLineTotalCents($course->getPrixCents());

    $order->setTotalCents($item->getLineTotalCents());
    $order->addItem($item);

    $em->persist($order);
    $em->persist($item);
    $em->flush();

    // Ici tu branches Stripe ensuite (checkout session), ou paiement offline.
    // Pour l’instant on redirige vers une page “en attente paiement”
    return $this->redirectToRoute('app_public_catalogue_order_pending', [
      'entite' => $entite->getId(),
      'ref' => $order->getReference(),
    ]);
  }

  #[IsGranted('ROLE_USER')]
  #[Route('/order/{ref}', name: 'order_pending', methods: ['GET'])]
  public function orderPending(Entite $entite, string $ref, EntityManagerInterface $em): Response
  {
    $order = $em->getRepository(ElearningOrder::class)->findOneBy(['entite' => $entite, 'reference' => $ref]);
    if (!$order) throw $this->createNotFoundException();

    return $this->render('public/catalogue/order_pending.html.twig', [
      'entite' => $entite,
      'order' => $order,
    ]);
  }
}
