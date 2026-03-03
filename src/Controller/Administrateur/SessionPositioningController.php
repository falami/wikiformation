<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{SessionPositioning, Entite, Utilisateur, Session};
use App\Form\Administrateur\SessionPositioningAddType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;


#[Route(
  '/administrateur/{entite}/sessions/{session}/positionnements',
  name: 'app_administrateur_session_positioning_',
  requirements: ['entite' => '\d+', 'session' => '\d+']
)]
#[IsGranted(TenantPermission::SESSION_POSITION_MANAGE, subject: 'entite')]
final class SessionPositioningController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET', 'POST'])]
  public function index(Entite $entite, Session $session, Request $req, EM $em): Response
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }


    /** @var Utilisateur $user */
    $user = $this->getUser();

    $form = $this->createForm(SessionPositioningAddType::class, null, [
      'entite' => $entite, // ✅ optionnel mais conseillé si ton form filtre les questionnaires par entité
    ]);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $questionnaire = $form->get('questionnaire')->getData();

      if (!$questionnaire || $questionnaire->getEntite()?->getId() !== $entite->getId()) {
        $this->addFlash('danger', 'Questionnaire invalide.');
        return $this->redirectToRoute('app_administrateur_session_positioning_index', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
        ]);
      }

      $existing = $em->getRepository(SessionPositioning::class)->findOneBy([
        'session' => $session,
        'questionnaire' => $questionnaire,
      ]);

      if ($existing) {
        $this->addFlash('warning', 'Ce questionnaire est déjà lié à la session.');
        return $this->redirectToRoute('app_administrateur_session_positioning_index', [
          'entite' => $entite->getId(),
          'session' => $session->getId(),
        ]);
      }

      $sp = (new SessionPositioning())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setSession($session)
        ->setQuestionnaire($questionnaire)
        ->setIsRequired((bool) $form->get('isRequired')->getData())
        ->setPosition((int) ($form->get('position')->getData() ?? 0));

      $em->persist($sp);
      $em->flush();

      // ✅ Important : on ne crée PAS de tentatives ici (tu veux rattacher plus tard)
      $this->addFlash('success', 'Questionnaire lié à la session (rattachement stagiaires possible plus tard).');

      return $this->redirectToRoute('app_administrateur_session_positioning_index', [
        'entite' => $entite->getId(),
        'session' => $session->getId(),
      ]);
    }

    $sessionPositionings = $em->getRepository(SessionPositioning::class)->findBy(
      ['session' => $session],
      ['position' => 'ASC', 'id' => 'ASC']
    );

    $utilisateurEntite = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    return $this->render('administrateur/session/positioning/index.html.twig', [
      'entite' => $entite,
      'session' => $session,
      'form' => $form->createView(),
      'sessionPositionings' => $sessionPositionings,
      'utilisateurEntite' => $utilisateurEntite,
    ]);
  }

  #[Route('/{sp}/delete', name: 'delete', methods: ['POST'], requirements: ['sp' => '\d+'])]
  public function delete(Entite $entite, Session $session, SessionPositioning $sp, Request $req, EM $em): Response
  {
    if ($session->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($sp->getSession()?->getId() !== $session->getId()) throw $this->createNotFoundException();

    if ($this->isCsrfTokenValid('del_sp_' . $sp->getId(), (string) $req->request->get('_token'))) {
      $em->remove($sp);
      $em->flush();
      $this->addFlash('success', 'Lien session ⇄ questionnaire supprimé.');
    }

    return $this->redirectToRoute('app_administrateur_session_positioning_index', [
      'entite' => $entite->getId(),
      'session' => $session->getId(),
    ]);
  }
}
