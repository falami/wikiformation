<?php

declare(strict_types=1);

namespace App\Controller\Stagiaire;

use App\Entity\{PositioningAttempt, Entite, Utilisateur, PositioningItem, PositioningAnswer};
use App\Form\Stagiaire\PositioningFillType;
use App\Enum\KnowledgeLevel;
use App\Enum\InterestChoice;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;

#[Route('/stagiaire/{entite}/positionnements', name: 'app_stagiaire_positioning_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::STAGIAIRE_POSITIONING_MANAGE, subject: 'entite')]
final class PositioningController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('/{attempt}/fill', name: 'fill', requirements: ['attempt' => '\d+'], methods: ['GET', 'POST'])]
  public function fill(Entite $entite, PositioningAttempt $attempt, Request $req, EM $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // ✅ sécurité: owner
    if ($attempt->getStagiaire()?->getId() !== $user->getId()) {
      throw $this->createAccessDeniedException();
    }

    // ✅ sécurité: entité via questionnaire (et non via session)
    $qEntiteId = $attempt->getQuestionnaire()?->getEntite()?->getId();
    if (!$qEntiteId || $qEntiteId !== $entite->getId()) {
      throw $this->createNotFoundException();
    }

    $items = $em->getRepository(PositioningItem::class)->createQueryBuilder('i')
      ->join('i.chapter', 'c')
      ->join('c.questionnaire', 'q')
      ->andWhere('q = :q')->setParameter('q', $attempt->getQuestionnaire())
      ->orderBy('c.position', 'ASC')
      ->addOrderBy('c.id', 'ASC')
      ->addOrderBy('i.position', 'ASC')
      ->addOrderBy('i.id', 'ASC')
      ->getQuery()->getResult();

    $form = $this->createForm(PositioningFillType::class, $attempt, ['items' => $items]);

    // 1) On charge les réponses existantes
    $answers = $em->getRepository(PositioningAnswer::class)->findForAttemptOrdered($attempt);

    // 2) On indexe par itemId
    $byItemId = [];
    foreach ($answers as $a) {
      $byItemId[$a->getItem()->getId()] = $a;
    }

    // 3) On applique : valeur sauvegardée si existe, sinon défaut NONE/YES
    foreach ($items as $item) {
      $id = $item->getId();

      $saved = $byItemId[$id] ?? null;

      if ($form->has("k_$id")) {
        $form->get("k_$id")->setData($saved?->getKnowledge() ?? KnowledgeLevel::NONE);
      }
      if ($form->has("i_$id")) {
        $form->get("i_$id")->setData($saved?->getInterest() ?? InterestChoice::YES);
      }
    }


    $form->handleRequest($req);
    if ($form->isSubmitted() && $form->isValid()) {

      $missing = false;

      foreach ($items as $item) {
        $id = $item->getId();

        $k = $form->has("k_$id") ? $form->get("k_$id")->getData() : null;
        $i = $form->has("i_$id") ? $form->get("i_$id")->getData() : null;

        if ($req->request->has('submit_final') && ($k === null || $i === null)) {
          $missing = true;
          break;
        }

        $answer = $em->getRepository(PositioningAnswer::class)->findOneBy([
          'attempt' => $attempt,
          'item' => $item,
        ]) ?? (new PositioningAnswer())->setAttempt($attempt)->setItem($item)->setCreateur($user)->setEntite($entite);

        $answer->setKnowledge($k);
        $answer->setInterest($i);
        $em->persist($answer);
      }

      if ($req->request->has('submit_final')) {
        if ($missing) {
          $this->addFlash('danger', 'Veuillez répondre à toutes les lignes avant de valider.');
          return $this->redirectToRoute('app_stagiaire_positioning_fill', [
            'entite' => $entite->getId(),
            'attempt' => $attempt->getId(),
          ]);
        }

        $attempt->setSubmittedAt(new \DateTimeImmutable());
      }

      $em->flush();
      $this->addFlash('success', 'Positionnement enregistré.');

      return $this->redirectToRoute('app_stagiaire_positioning_list', [
        'entite' => $entite->getId(),
      ]);
    }


    $utilisateurEntite = $this->utilisateurEntiteManager->getUserEntiteLink($entite);

    $computedLevelInt = null;
    if ($attempt->getId()) {
      $map = $em->getRepository(PositioningAnswer::class)
        ->computeLevelByAttemptIds([$attempt->getId()]);
      $computedLevelInt = $map[$attempt->getId()] ?? null;
    }


    return $this->render('stagiaire/positioning/fill.html.twig', [
      'entite' => $entite,
      'attempt' => $attempt,
      'items' => $items,
      'form' => $form->createView(),
      'computedLevelInt' => $computedLevelInt,
      'utilisateurEntite' => $utilisateurEntite,
    ]);
  }
}
