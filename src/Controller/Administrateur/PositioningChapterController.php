<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{PositioningQuestionnaire, Entite, Utilisateur, PositioningChapter};
use App\Form\Administrateur\PositioningChapterType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Repository\PositioningChapterRepository;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/positionnements/questionnaires/{questionnaire}/chapitres', name: 'app_administrateur_positioning_chapter_', requirements: ['entite' => '\d+', 'questionnaire' => '\d+'])]
#[IsGranted(TenantPermission::POSITIONING_CHAPTER_MANAGE, subject: 'entite')]
final class PositioningChapterController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}



  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(
    Entite $entite,
    PositioningQuestionnaire $questionnaire,
    Request $req,
    EM $em,
    PositioningChapterRepository $chapterRepo
  ): Response {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $chapter = (new PositioningChapter())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setQuestionnaire($questionnaire);

    // ✅ N+1 automatique
    $chapter->setPosition($chapterRepo->getNextPositionForQuestionnaire($questionnaire));

    $form = $this->createForm(PositioningChapterType::class, $chapter, [
      'questionnaire' => $questionnaire,
    ]);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($chapter);
      $em->flush();

      $this->addFlash('success', 'Chapitre créé.');
      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite->getId(),
        'id' => $questionnaire->getId(),
      ]);
    }

    return $this->render('administrateur/positioning/chapter/form.html.twig', [
      'entite' => $entite,
      'questionnaire' => $questionnaire,
      'chapter' => $chapter,
      'form' => $form->createView(),
      'is_edit' => false,
    ]);
  }

  #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
  public function edit(Entite $entite, PositioningQuestionnaire $questionnaire, PositioningChapter $chapter, Request $req, EM $em): Response
  {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();
    $form = $this->createForm(PositioningChapterType::class, $chapter, [
      'questionnaire' => $questionnaire,
    ]);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Chapitre mis à jour.');
      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite->getId(),
        'id' => $questionnaire->getId(),
      ]);
    }

    return $this->render('administrateur/positioning/chapter/form.html.twig', [
      'entite' => $entite,
      'questionnaire' => $questionnaire,
      'chapter' => $chapter,
      'form' => $form->createView(),
      'is_edit' => true,
    ]);
  }

  #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function delete(Entite $entite, PositioningQuestionnaire $questionnaire, PositioningChapter $chapter, Request $req, EM $em): Response
  {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();
    if ($this->isCsrfTokenValid('del_ch_' . $chapter->getId(), (string)$req->request->get('_token'))) {
      $em->remove($chapter);
      $em->flush();
      $this->addFlash('success', 'Chapitre supprimé.');
    }

    return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
      'entite' => $entite->getId(),
      'id' => $questionnaire->getId(),
    ]);
  }
}
