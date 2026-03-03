<?php

namespace App\Controller\Super;

use App\Entity\{Entite, Utilisateur};
use App\Entity\UtilisateurEntite;
use App\Form\Super\UtilisateurEntiteType;
use App\Repository\UtilisateurEntiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;

#[IsGranted('ROLE_SUPER')]
#[Route('/super/entites/{entite}', name: 'app_super_entite_users_', requirements: ['entite' => '\d+'])]
final class EntiteUsersController extends AbstractController
{

  public function __construct(
    private readonly UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}
  #[Route('/users', name: 'index', methods: ['GET'])]
  public function index(
    Entite $entite,
    Request $request,
    UtilisateurEntiteRepository $ueRepo,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $q = trim((string)$request->query->get('q', ''));
    $items = $ueRepo->findForEntite($entite, $q);

    return $this->render('super/entite_users/index.html.twig', [
      'entite' => $entite,
      'items' => $items,
      'q' => $q,

    ]);
  }

  #[Route('/users/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(
    Entite $entite,
    Request $request,
    EntityManagerInterface $em,
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $ue = new UtilisateurEntite();
    $ue->setEntite($entite);
    $ue->setCreateur($this->getUser()); // SUPER créateur
    $ue->ensureCouleur();

    $form = $this->createForm(UtilisateurEntiteType::class, $ue, ['is_new' => true]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      // sécurité : éviter doublon (contrainte unique en DB, mais on peut gérer propre)
      $ue->ensureCouleur();
      $em->persist($ue);
      $em->flush();

      $this->addFlash('success', 'Utilisateur rattaché à l’entité.');
      return $this->redirectToRoute('app_super_entite_users_index', ['entite' => $entite->getId()]);
    }

    return $this->render('super/entite_users/edit.html.twig', [
      'entite' => $entite,
      'item' => $ue,
      'form' => $form->createView(),
      'isNew' => true,

    ]);
  }

  #[Route('/users/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
  public function edit(
    Entite $entite,
    UtilisateurEntite $ue,
    Request $request,
    EntityManagerInterface $em,
  ): Response {
    if ($ue->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException('Rattachement invalide.');
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(UtilisateurEntiteType::class, $ue, ['is_new' => false]);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $ue->ensureCouleur();
      $em->flush();

      $this->addFlash('success', 'Rattachement mis à jour.');
      return $this->redirectToRoute('app_super_entite_users_index', [
        'entite' => $entite->getId(),

      ]);
    }

    return $this->render('super/entite_users/edit.html.twig', [
      'entite' => $entite,
      'item' => $ue,
      'form' => $form->createView(),
      'isNew' => false,

    ]);
  }

  #[Route('/users/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
  public function delete(
    Entite $entite,
    UtilisateurEntite $ue,
    Request $request,
    EntityManagerInterface $em,
  ): RedirectResponse {
    if ($ue->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }
    /** @var Utilisateur $user */
    $user = $this->getUser();

    if (!$this->isCsrfTokenValid('ue_del_' . $ue->getId(), (string)$request->request->get('_token'))) {
      $this->addFlash('danger', 'Token CSRF invalide.');
      return $this->redirectToRoute('app_super_entite_users_index', [
        'entite' => $entite->getId(),

      ]);
    }

    $em->remove($ue);
    $em->flush();

    $this->addFlash('warning', 'Utilisateur retiré de l’entité.');
    return $this->redirectToRoute('app_super_entite_users_index', [
      'entite' => $entite->getId(),

    ]);
  }
}
