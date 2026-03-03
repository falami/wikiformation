<?php
// src/Controller/Administrateur/ElearningContentReorderController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningNode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Security\Permission\TenantPermission;



#[Route('/admin/{entite}/{course}/elearning/content', name: 'app_administrateur_elearning_content_')]
#[IsGranted(TenantPermission::ELEARNING_CONTENT_REORDER_MANAGE, subject: 'entite')]
final class ElearningContentReorderController extends AbstractController
{
  public function __construct(private CsrfTokenManagerInterface $csrf) {}

  #[Route('/reorder', name: 'reorder', methods: ['POST'])]
  public function reorder(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em
  ): JsonResponse {
    $payload = json_decode($request->getContent() ?: 'null', true);

    if ($payload === null) {
      return new JsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
    }

    // objet unique OU tableau d’objets
    $items = \is_array($payload) && isset($payload[0]) ? $payload : [$payload];

    // CSRF optionnel (si fourni)
    $tokenValue = $items[0]['_token'] ?? null;
    if ($tokenValue !== null) {
      if (!$this->csrf->isTokenValid(new CsrfToken('reorder_nodes_' . $course->getId(), $tokenValue))) {
        return new JsonResponse(['success' => false, 'error' => 'Bad CSRF token'], 403);
      }
    }

    $nodeRepo = $em->getRepository(ElearningNode::class);

    try {
      $em->wrapInTransaction(function (EntityManagerInterface $em) use ($items, $nodeRepo, $course) {

        foreach ($items as $row) {
          $id       = (int)($row['id'] ?? 0);
          $parentId = $row['parentId'] === null ? null : (int)($row['parentId'] ?? 0);
          $pos      = (int)($row['position'] ?? 0);

          /** @var ElearningNode|null $node */
          $node = $nodeRepo->find($id);
          if (!$node || $node->getCourse()?->getId() !== $course->getId()) {
            continue;
          }

          $oldParent = $node->getParent();

          // nouveau parent
          $newParent = null;
          if ($parentId !== null) {
            $newParent = $nodeRepo->find($parentId);
            if (!$newParent || $newParent->getCourse()?->getId() !== $course->getId()) {
              throw new \RuntimeException('Invalid parentId');
            }
            if ($this->isDescendantOf($newParent, $node)) {
              throw new \RuntimeException('Cannot move a node under one of its descendants');
            }
          }

          $parentChanged = ($oldParent?->getId() !== $newParent?->getId());

          $node->setParent($newParent);
          $node->setPosition(max(0, $pos));

          // updatedAt si dispo (tu l’as ajouté)
          if (method_exists($node, 'setUpdatedAt')) {
            $node->setUpdatedAt(new \DateTime());
          }

          // Re-index fratrie du nouveau parent
          $this->reindexSiblings($em, $course, $newParent, $node);

          // Si parent changé : re-index fratrie de l'ancien parent
          if ($parentChanged) {
            $this->reindexSiblings($em, $course, $oldParent, null);
          }
        }
      });
    } catch (\Throwable $e) {
      return new JsonResponse([
        'success' => false,
        'error'   => 'Reorder failed: ' . $e->getMessage(),
      ], 400);
    }

    return new JsonResponse(['success' => true]);
  }

  #[Route('/{id}/delete', name: 'supprimer', methods: ['POST'])]
  public function delete(
    Entite $entite,
    ElearningCourse $course,
    ElearningNode $node,
    Request $request,
    EntityManagerInterface $em
  ): JsonResponse {
    $token = $request->request->get('_token');
    if (!$this->csrf->isTokenValid(new CsrfToken('del_node_' . $node->getId(), (string) $token))) {
      return new JsonResponse(['success' => false, 'error' => 'Bad CSRF token'], 403);
    }

    if ($node->getCourse()?->getId() !== $course->getId()) {
      return new JsonResponse(['success' => false, 'error' => 'Node/course mismatch'], 400);
    }

    $parent = $node->getParent();

    $em->wrapInTransaction(function (EntityManagerInterface $em) use ($node, $course, $parent) {
      $em->remove($node);
      $em->flush();
      $this->reindexSiblings($em, $course, $parent, null);
    });

    return new JsonResponse(['success' => true]);
  }

  private function reindexSiblings(
    EntityManagerInterface $em,
    ElearningCourse $course,
    ?ElearningNode $parent,
    ?ElearningNode $includeNode
  ): void {
    $qb = $em->createQueryBuilder()
      ->select('n')
      ->from(ElearningNode::class, 'n')
      ->andWhere('n.course = :c')
      ->setParameter('c', $course)
      ->orderBy('n.position', 'ASC')
      ->addOrderBy('n.id', 'ASC');

    if ($parent === null) {
      $qb->andWhere('n.parent IS NULL');
    } else {
      $qb->andWhere('n.parent = :p')->setParameter('p', $parent);
    }

    /** @var ElearningNode[] $siblings */
    $siblings = $qb->getQuery()->getResult();

    if ($includeNode && !\in_array($includeNode, $siblings, true)) {
      $siblings[] = $includeNode;
    }

    usort($siblings, function (ElearningNode $a, ElearningNode $b) {
      $pa = $a->getPosition() ?? 0;
      $pb = $b->getPosition() ?? 0;
      return $pa <=> $pb ?: $a->getId() <=> $b->getId();
    });

    foreach ($siblings as $i => $s) {
      $s->setParent($parent);
      $s->setPosition($i);

      if (method_exists($s, 'setUpdatedAt')) {
        $s->setUpdatedAt(new \DateTime());
      }
    }

    $em->flush();
  }

  private function isDescendantOf(ElearningNode $a, ElearningNode $b): bool
  {
    $cur = $a;
    while ($cur) {
      if ($cur->getId() === $b->getId()) return true;
      $cur = $cur->getParent();
    }
    return false;
  }
}
