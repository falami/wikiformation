<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{PositioningQuestionnaire, Entite, Utilisateur, PositioningItem, PositioningChapter};
use App\Form\Administrateur\PositioningItemType;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Repository\PositioningItemRepository;
use App\Security\Permission\TenantPermission;

#[Route('/administrateur/{entite}/positionnements/questionnaires/{questionnaire}/chapitres/{chapter}/items', name: 'app_administrateur_positioning_item_', requirements: ['entite' => '\d+', 'questionnaire' => '\d+', 'chapter' => '\d+'])]
#[IsGranted(TenantPermission::POSITIONING_ITEM_MANAGE, subject: 'entite')]
final class PositioningItemController extends AbstractController
{

  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}


  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(
    Entite $entite,
    PositioningQuestionnaire $questionnaire,
    PositioningChapter $chapter,
    Request $req,
    EM $em,
    PositioningItemRepository $itemRepo
  ): Response {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();

    $item = (new PositioningItem())->setChapter($chapter)
      ->setCreateur($user)
      ->setEntite($entite);

    // ✅ N+1 automatique
    $item->setPosition($itemRepo->getNextPositionForChapter($chapter));

    $form = $this->createForm(PositioningItemType::class, $item);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->persist($item);
      $em->flush();

      $this->addFlash('success', 'Item créé.');
      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite->getId(),
        'id' => $questionnaire->getId(),

      ]);
    }

    return $this->render('administrateur/positioning/item/form.html.twig', [
      'entite' => $entite,
      'questionnaire' => $questionnaire,
      'chapter' => $chapter,
      'item' => $item,
      'form' => $form->createView(),
      'is_edit' => false,

    ]);
  }

  #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
  public function edit(Entite $entite, PositioningQuestionnaire $questionnaire, PositioningChapter $chapter, PositioningItem $item, Request $req, EM $em): Response
  {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) throw $this->createNotFoundException();
    if ($item->getChapter()?->getId() !== $chapter->getId()) throw $this->createNotFoundException();


    /** @var Utilisateur $user */
    $user = $this->getUser();
    $form = $this->createForm(PositioningItemType::class, $item);
    $form->handleRequest($req);

    if ($form->isSubmitted() && $form->isValid()) {
      $em->flush();
      $this->addFlash('success', 'Item mis à jour.');
      return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
        'entite' => $entite->getId(),
        'id' => $questionnaire->getId(),

      ]);
    }

    return $this->render('administrateur/positioning/item/form.html.twig', [
      'entite' => $entite,
      'questionnaire' => $questionnaire,
      'chapter' => $chapter,
      'item' => $item,
      'form' => $form->createView(),
      'is_edit' => true,

    ]);
  }

  #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
  public function delete(Entite $entite, PositioningQuestionnaire $questionnaire, PositioningChapter $chapter, PositioningItem $item, Request $req, EM $em): Response
  {
    if ($questionnaire->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    if ($chapter->getQuestionnaire()?->getId() !== $questionnaire->getId()) throw $this->createNotFoundException();
    if ($item->getChapter()?->getId() !== $chapter->getId()) throw $this->createNotFoundException();

    /** @var Utilisateur $user */
    $user = $this->getUser();
    if ($this->isCsrfTokenValid('del_it_' . $item->getId(), (string)$req->request->get('_token'))) {
      $em->remove($item);
      $em->flush();
      $this->addFlash('success', 'Item supprimé.');
    }

    return $this->redirectToRoute('app_administrateur_positioning_questionnaire_edit', [
      'entite' => $entite->getId(),
      'id' => $questionnaire->getId(),
    ]);
  }
}
