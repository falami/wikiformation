<?php
// src/Controller/Administrateur/FormationContentReorderController.php
declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\{FormationContentNode, Entite, Formation};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\{JsonResponse, Request};
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use App\Security\Permission\TenantPermission;

#[Route('/admin/{entite}/{formation}/content', name: 'app_administrateur_formation_content_')]
#[IsGranted(TenantPermission::FORMATION_CONTENT_REORDER_MANAGE, subject: 'entite')]
final class FormationContentReorderController extends AbstractController
{
    public function __construct(private CsrfTokenManagerInterface $csrf) {}

    /**
     * Reorder/move a node. Payload:
     * - Single: { "id": 12, "parentId": null|5, "position": 2, "_token": "..." }
     * - Or list: [ { "id": 12, "parentId": 5, "position": 0 }, ... ]
     */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(
        Entite $entite,
        Formation $formation,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        $payload = json_decode($request->getContent() ?: 'null', true);

        if ($payload === null) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid JSON'], 400);
        }

        // Supporte objet unique OU tableau d’objets
        $items = \is_array($payload) && isset($payload[0]) ? $payload : [$payload];

        // Si un _token est présent sur l’objet unique, vérifie-le
        $tokenValue = $items[0]['_token'] ?? null;
        if ($tokenValue !== null) {
            if (!$this->csrf->isTokenValid(new CsrfToken('reorder_nodes_' . $formation->getId(), $tokenValue))) {
                return new JsonResponse(['success' => false, 'error' => 'Bad CSRF token'], 403);
            }
        }

        $nodeRepo = $em->getRepository(FormationContentNode::class);

        try {
            $em->wrapInTransaction(function (EntityManagerInterface $em) use ($items, $nodeRepo, $formation) {

                foreach ($items as $row) {
                    $id       = (int)($row['id']       ?? 0);
                    $parentId = $row['parentId'] === null ? null : (int)$row['parentId'];
                    $pos      = (int)($row['position']  ?? 0);

                    /** @var FormationContentNode|null $node */
                    $node = $nodeRepo->find($id);
                    if (!$node || $node->getFormation()?->getId() !== $formation->getId()) {
                        // Ignore silencieusement si l’élément n’appartient pas à cette formation
                        continue;
                    }

                    $oldParent = $node->getParent();

                    // Trouver et valider le nouveau parent (peut être null)
                    $newParent = null;
                    if ($parentId !== null) {
                        $newParent = $nodeRepo->find($parentId);
                        // parent doit exister, être de la même formation, et ne pas créer de cycle
                        if (!$newParent || $newParent->getFormation()?->getId() !== $formation->getId()) {
                            throw new \RuntimeException('Invalid parentId');
                        }
                        if ($this->isDescendantOf($newParent, $node)) {
                            throw new \RuntimeException('Cannot move a node under one of its descendants');
                        }
                    }

                    // Si parent change, on sort le node des anciens frères avant de reindexer
                    $parentChanged = ($oldParent?->getId() !== $newParent?->getId());

                    $node->setParent($newParent);
                    $node->setPosition(max(0, $pos));

                    // Re-indexation des FRÈRES du nouveau parent (incluant $node)
                    $this->reindexSiblings($em, $formation, $newParent, $node);

                    // Si le parent a changé : re-indexe aussi les FRÈRES de l’ancien parent (dont on vient de retirer $node)
                    if ($parentChanged) {
                        $this->reindexSiblings($em, $formation, $oldParent, null);
                    }
                }
            });
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error'   => 'Reorder failed: ' . $e->getMessage()
            ], 400);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * Suppression d’un nœud (POST, CSRF).
     * Cascade sur les enfants si votre mapping le prévoit (JoinColumn onDelete="CASCADE").
     */
    #[Route('/{id}/delete', name: 'supprimer', methods: ['POST'])]
    public function delete(
        Entite $entite,
        Formation $formation,
        FormationContentNode $node,
        Request $request,
        EntityManagerInterface $em
    ): JsonResponse {
        // CSRF: token name = del_node_{id}
        $token = $request->request->get('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('del_node_' . $node->getId(), $token))) {
            return new JsonResponse(['success' => false, 'error' => 'Bad CSRF token'], 403);
        }

        if ($node->getFormation()?->getId() !== $formation->getId()) {
            return new JsonResponse(['success' => false, 'error' => 'Node/formation mismatch'], 400);
        }

        $parent = $node->getParent();

        $em->wrapInTransaction(function (EntityManagerInterface $em) use ($node, $formation, $parent) {
            $em->remove($node);
            $em->flush();
            // Re-indexe les frères restants
            $this->reindexSiblings($em, $formation, $parent, null);
        });

        // Réponse JSON (utile pour l’AJAX de ton template)
        return new JsonResponse(['success' => true]);
    }

    /**
     * Re-indexe toutes les positions (0..n) des frères pour un parent donné.
     * Si $includeNode est fourni, il est intégré dans le tri selon sa position courante.
     *
     * @param FormationContentNode|null $parent  null = racine (chapitres)
     * @param FormationContentNode|null $includeNode  facultatif
     */
    private function reindexSiblings(
        EntityManagerInterface $em,
        Formation $formation,
        ?FormationContentNode $parent,
        ?FormationContentNode $includeNode
    ): void {
        $qb = $em->createQueryBuilder()
            ->select('n')
            ->from(FormationContentNode::class, 'n')
            ->andWhere('n.formation = :f')
            ->setParameter('f', $formation)
            ->orderBy('n.position', 'ASC')
            ->addOrderBy('n.id', 'ASC');

        if ($parent === null) {
            $qb->andWhere('n.parent IS NULL');
        } else {
            $qb->andWhere('n.parent = :p')->setParameter('p', $parent);
        }

        /** @var FormationContentNode[] $siblings */
        $siblings = $qb->getQuery()->getResult();

        // Si $includeNode n’est pas encore dedans (ex: vient de changer de parent), on l’ajoute virtuellement
        if ($includeNode && !\in_array($includeNode, $siblings, true)) {
            $siblings[] = $includeNode;
        }

        // Trie par position demandée puis ID (stabilité)
        usort($siblings, function (FormationContentNode $a, FormationContentNode $b) {
            $pa = $a->getPosition() ?? 0;
            $pb = $b->getPosition() ?? 0;
            return $pa <=> $pb ?: $a->getId() <=> $b->getId();
        });

        // Ré-assigne 0..n
        foreach ($siblings as $i => $s) {
            $s->setParent($parent);  // sécurise le parent pour tous
            $s->setPosition($i);
        }

        $em->flush();
    }

    /**
     * Test utilitaire : $a est-il descendant de $b ?
     */
    private function isDescendantOf(FormationContentNode $a, FormationContentNode $b): bool
    {
        $cur = $a;
        while ($cur) {
            if ($cur->getId() === $b->getId()) return true;
            $cur = $cur->getParent();
        }
        return false;
    }
}
