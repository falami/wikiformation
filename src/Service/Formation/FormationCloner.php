<?php

namespace App\Service\Formation;

use App\Entity\Formation;
use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\FormationPhoto;
use App\Entity\FormationContentNode;
use App\Entity\ContentBlock;

use App\Entity\Quiz;
use App\Entity\QuizQuestion;
use App\Entity\QuizChoice;

final class FormationCloner
{
  public function cloneFormation(Formation $src, Utilisateur $user, Entite $entite, ?string $suffix = null): Formation
  {
    $suffix ??= substr(md5(uniqid('', true)), 0, 8);


    $copy = new Formation();
    $copy
      ->setTitre($src->getTitre() . ' (copie)')
      ->setSlug($src->getSlug() . '-copie-' . $suffix)
      ->setNiveau($src->getNiveau())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setDescription($src->getDescription())
      ->setDuree($src->getDuree())
      ->setPrixBaseCents($src->getPrixBaseCents())
      ->setPrixReduitCents($src->getPrixReduitCents())
      ->setCodeQualiopi($src->getCodeQualiopi())
      ->setConditionPrealable($src->getConditionPrealable())
      ->setPedagogie($src->getPedagogie())
      ->setObjectifs($src->getObjectifs())
      ->setPublic($src->getPublic())
      ->setModalitesPratiques($src->getModalitesPratiques())
      ->setModalitesEvaluation($src->getModalitesEvaluation())
      ->setAdresse($src->getAdresse())
      ->setComplement($src->getComplement())
      ->setCodePostal($src->getCodePostal())
      ->setVille($src->getVille())
      ->setDepartement($src->getDepartement())
      ->setRegion($src->getRegion())
      ->setPays($src->getPays())
      ->setPhotoCouverture($src->getPhotoCouverture())
      ->setPhotoBanniere($src->getPhotoBanniere())
      ->setEntite($src->getEntite())
      ->setFormateur($src->getFormateur())
      ->setEngin($src->getEngin())
      ->setSite($src->getSite());

    // 1) Photos
    foreach ($src->getPhotos() as $p) {
      $np = (new FormationPhoto())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setFilename($p->getFilename())
        ->setPosition($p->getPosition());
      $copy->addPhoto($np);
    }

    // 2) Nodes + children + blocks (récursif depuis les racines)
    $roots = $this->getRootNodes($src);
    foreach ($roots as $root) {
      $newRoot = $this->cloneNodeRecursive($root, $copy, $user, $entite); // ✅ ici
      $copy->addContentNode($newRoot);
    }


    return $copy;
  }

  /** @return FormationContentNode[] */
  private function getRootNodes(Formation $formation): array
  {
    $roots = [];
    foreach ($formation->getContentNodes() as $n) {
      if ($n->getParent() === null) {
        $roots[] = $n;
      }
    }

    usort(
      $roots,
      fn(FormationContentNode $a, FormationContentNode $b) => $a->getPosition() <=> $b->getPosition()
    );

    return $roots;
  }

  private function cloneNodeRecursive(FormationContentNode $srcNode, Formation $targetFormation, Utilisateur $user, Entite $entite): FormationContentNode
  {
    $newNode = new FormationContentNode();

    $newNode
      ->setFormation($targetFormation) // ✅ IMPORTANT
      ->setType($srcNode->getType())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setTitre($srcNode->getTitre())
      ->setSlug($srcNode->getSlug())
      ->setPosition($srcNode->getPosition())
      ->setDureeMinutes($srcNode->getDureeMinutes())
      ->setIsPublished($srcNode->isPublished())
      ->setVersion($srcNode->getVersion())
      ->setCreatedAt(new \DateTimeImmutable())
      ->setUpdatedAt(new \DateTime());

    foreach ($srcNode->getBlocks() as $block) {
      $newNode->addBlock($this->cloneContentBlock($block, $user, $entite));
    }

    foreach ($srcNode->getChildren() as $child) {
      $newChild = $this->cloneNodeRecursive($child, $targetFormation, $user, $entite);
      $newNode->addChild($newChild);
    }

    return $newNode;
  }


  private function cloneContentBlock(ContentBlock $src, Utilisateur $user, Entite $entite): ContentBlock
  {
    $copy = new ContentBlock();
    $copy
      ->setType($src->getType())
      ->setCreateur($user)
      ->setEntite($entite)
      ->setContent($src->getContent())
      ->setMediaFilename($src->getMediaFilename())
      ->setMediaUrl($src->getMediaUrl())
      ->setMeta($src->getMeta())
      ->setPosition($src->getPosition())
      ->setIsRequired($src->isRequired());

    // Quiz (si présent)
    $quiz = $src->getQuiz();
    if ($quiz instanceof Quiz) {
      $copy->setQuiz($this->cloneQuiz($quiz, $user, $entite));
    }

    return $copy;
  }

  /**
   * Clone un quiz COMPLET : Quiz -> Questions -> Choices
   * (sans cloner Attempt / Answer)
   */
  private function cloneQuiz(Quiz $srcQuiz, Utilisateur $user, Entite $entite): Quiz
  {
    $newQuiz = new Quiz();
    $newQuiz->setTitle(($srcQuiz->getTitle() ?? 'Quiz') . ' (copie)');
    $newQuiz->setSettings($srcQuiz->getSettings());

    $newQuiz->setCreateur($user);
    $newQuiz->setEntite($entite);

    foreach ($srcQuiz->getQuestions() as $q) {
      $newQ = new QuizQuestion();
      $newQ
        ->setType($q->getType())
        ->setCreateur($user)
        ->setEntite($entite)
        ->setText($q->getText())
        ->setPosition($q->getPosition())
        ->setExplanation($q->getExplanation())
        ->setExpectedText($q->getExpectedText());

      $newQuiz->addQuestion($newQ);

      foreach ($q->getChoices() as $c) {
        $newC = new QuizChoice();
        $newC
          ->setLabel($c->getLabel())
          ->setCreateur($user)
          ->setEntite($entite)
          ->setIsCorrect($c->isCorrect())
          ->setPosition($c->getPosition());

        $newQ->addChoice($newC);
      }
    }

    return $newQuiz;
  }
}
