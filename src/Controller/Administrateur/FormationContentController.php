<?php

namespace App\Controller\Administrateur;

use App\Entity\{FormationContentNode, Entite, Utilisateur, Quiz, Formation, ContentBlock};
use App\Enum\BlockType;
use App\Form\Administrateur\ContentBlockType;
use App\Form\Administrateur\FormationContentNodeType;
use App\Repository\FormationContentNodeRepository;
use App\Service\FileUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use App\Security\Permission\TenantPermission;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/administrateur/{entite}/formation/{formation}/contenu')]
#[IsGranted(TenantPermission::FORMATION_CONTENT_MANAGE, subject: 'entite')]
class FormationContentController extends AbstractController
{
    public function __construct(
        private FileUploader $fileUploader
    ) {}

    #[Route('', name: 'app_administrateur_formation_content_index', methods: ['GET'])]
    public function index(Entite $entite, Formation $formation, FormationContentNodeRepository $repo): Response
    {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $roots = $repo->rootsForFormation($formation);
        return $this->render('administrateur/formation/content/index.html.twig', [
            'entite'     => $entite,
            'formation'  => $formation,
            'roots'      => $roots,

        ]);
    }

    #[Route('/node/ajouter', name: 'app_administrateur_formation_content_node_add', methods: ['GET', 'POST'])]
    #[Route('/node/{node}/modifier', name: 'app_administrateur_formation_content_node_edit', methods: ['GET', 'POST'])]
    public function nodeAddEdit(
        Entite $entite,
        Formation $formation,
        Request $request,
        EntityManagerInterface $em,
        ?FormationContentNode $node = null
    ): Response {


        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool)$node;
        $node ??= (new FormationContentNode())->setFormation($formation)->setCreateur($user)->setEntite($entite);

        // parentId optionnel (création d’un sous-chapitre)
        // parentId optionnel (création d’un sous-chapitre)
        $parentId = $request->query->getInt('parentId', 0);
        if (!$isEdit && $parentId > 0) {
            $parent = $em->getRepository(FormationContentNode::class)->find($parentId);

            // sécurité : même formation + pas de 3ème niveau
            if ($parent && $parent->getFormation()?->getId() === $formation->getId()) {
                // ⚠️ si le parent a déjà un parent => c'est un sous-chapitre => on refuse 3ème niveau
                if ($parent->getParent() !== null) {
                    $this->addFlash('warning', 'Impossible : pas de 3ème niveau (un sous-chapitre ne peut pas avoir de sous-chapitre).');
                    return $this->redirectToRoute('app_administrateur_formation_content_index', [
                        'entite' => $entite->getId(),
                        'formation' => $formation->getId(),
                    ]);
                }

                $node->setParent($parent);

                // ✅ Pré-remplissage à partir du chapitre cliqué
                $copyFromId = $request->query->getInt('copyFromId', 0);
                if ($copyFromId > 0 && $copyFromId === $parent->getId()) {

                    // ✅ On force le type SOUS_CHAPITRE (ton enum existe : App\Enum\NodeType)
                    $node->setType(\App\Enum\NodeType::SOUS_CHAPITRE);

                    // (optionnel) copier durée / publication
                    $node->setDureeMinutes($parent->getDureeMinutes());
                    $node->setIsPublished($parent->isPublished());
                }
            }
        }

        $form = $this->createForm(FormationContentNodeType::class, $node)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $node->setUpdatedAt(new \DateTime());
            $em->persist($node);
            $em->flush();

            $this->addFlash('success', $isEdit ? 'Chapitre modifié.' : 'Chapitre ajouté.');
            return $this->redirectToRoute('app_administrateur_formation_content_index', [
                'entite' => $entite->getId(),
                'formation' => $formation->getId(),
            ]);
        }

        return $this->render('administrateur/formation/content/node_form.html.twig', [
            'entite'    => $entite,
            'formation' => $formation,
            'node'      => $node,
            'form'      => $form->createView(),
            'modeEdition' => $isEdit,
            'entite' => $entite,
        ]);
    }

    #[Route('/node/{node}/supprimer', name: 'app_administrateur_formation_content_node_delete', methods: ['POST'])]
    public function nodeDelete(Entite $entite, Formation $formation, FormationContentNode $node, EntityManagerInterface $em, Request $request): RedirectResponse|JsonResponse
    {


        // (Optionnel) CSRF
        // if (!$this->isCsrfTokenValid('del_node_'.$node->getId(), $request->request->get('_token'))) { ... }

        $em->remove($node);
        $em->flush();

        if ($request->isXmlHttpRequest()) return new JsonResponse(['success' => true]);
        $this->addFlash('success', 'Chapitre supprimé.');
        return $this->redirectToRoute('app_administrateur_formation_content_index', [
            'entite' => $entite->getId(),
            'formation' => $formation->getId(),
        ]);
    }

    #[Route('/node/reorder', name: 'app_administrateur_formation_content_node_reorder', methods: ['POST'])]
    public function nodeReorder(Entite $entite, Formation $formation, Request $request, EntityManagerInterface $em): JsonResponse
    {


        // Payload attendu: [{id: 12, position:0, parentId:null}, ...]
        $items = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($items)) return new JsonResponse(['success' => false, 'message' => 'Payload invalide'], 400);

        $repo = $em->getRepository(FormationContentNode::class);
        foreach ($items as $row) {
            $node = $repo->find((int)($row['id'] ?? 0));
            if (!$node || $node->getFormation()?->getId() !== $formation->getId()) continue;

            $node->setPosition((int)($row['position'] ?? 0));
            $parentId = $row['parentId'] ?? null;
            if ($parentId) {
                $parent = $repo->find((int)$parentId);
                if ($parent && $parent->getFormation()?->getId() === $formation->getId()) {
                    $node->setParent($parent);
                }
            } else {
                $node->setParent(null);
            }
        }
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/node/{node}/block/ajouter', name: 'app_administrateur_formation_content_block_add', methods: ['GET', 'POST'])]
    #[Route('/block/{block}/modifier', name: 'app_administrateur_formation_content_block_edit', methods: ['GET', 'POST'])]
    public function blockAddEdit(
        Entite $entite,
        Formation $formation,
        Request $request,
        EntityManagerInterface $em,
        ?FormationContentNode $node = null,
        ?ContentBlock $block = null
    ): Response {



        // 1) Si on est sur la route EDIT mais que le bloc n’existe plus → rediriger proprement
        if ($request->attributes->get('_route') === 'app_administrateur_formation_content_block_edit' && !$block) {
            $nodeIdFromQuery = $request->query->getInt('node', 0);
            if ($nodeIdFromQuery > 0) {
                $node = $em->getRepository(FormationContentNode::class)->find($nodeIdFromQuery);
                if ($node && $node->getFormation()?->getId() === $formation->getId()) {
                    $this->addFlash('warning', 'Ce bloc n’existe plus (il a peut-être été supprimé).');
                    return $this->redirectToRoute('app_administrateur_formation_content_block_add', [
                        'entite'    => $entite->getId(),
                        'formation' => $formation->getId(),
                        'node'      => $node->getId(),
                    ]);
                }
            }
            // fallback : retour à l’index
            $this->addFlash('warning', 'Ce bloc n’existe plus.');
            return $this->redirectToRoute('app_administrateur_formation_content_index', [
                'entite' => $entite->getId(),
                'formation' => $formation->getId(),
            ]);
        }

        // 2) Si on est en "création" (pas de $block), on s’assure de récupérer le node depuis la query ?node=
        if (!$block && !$node) {
            $nodeIdFromQuery = $request->query->getInt('node', 0);
            if ($nodeIdFromQuery > 0) {
                $candidate = $em->getRepository(FormationContentNode::class)->find($nodeIdFromQuery);
                if ($candidate && $candidate->getFormation()?->getId() === $formation->getId()) {
                    $node = $candidate;
                }
            }
        }




        /** @var Utilisateur $user */
        $user = $this->getUser();
        $isEdit = (bool)$block;
        if (!$isEdit) {
            if (!$node || $node->getFormation()?->getId() !== $formation->getId()) {
                throw $this->createNotFoundException();
            }
            $block = (new ContentBlock())->setNode($node)->setCreateur($user)->setEntite($entite);
        } else {
            if ($block?->getNode()?->getFormation()?->getId() !== $formation->getId()) {
                throw $this->createAccessDeniedException();
            }
        }

        $form = $this->createForm(ContentBlockType::class, $block, [
            'createur' => $this->getUser(),
            'entite'   => $entite,
        ])->handleRequest($request);

        // ⬇️ D'abord: si soumis MAIS invalide et AJAX → 422 avec le formulaire rerendu
        if ($form->isSubmitted() && !$form->isValid() && $request->isXmlHttpRequest()) {
            $formHtml = $this->renderView('administrateur/formation/content/block_form.html.twig', [
                'entite'    => $entite,
                'formation' => $formation,
                'node'      => $node ?? $block->getNode(),
                'block'     => $block,
                'form'      => $form->createView(),
                'modeEdition' => $isEdit,

            ]);
            return new JsonResponse(['success' => false, 'formHtml' => $formHtml], 422);
        }


        // ...
        try {
            if ($form->isSubmitted() && $form->isValid()) {

                // upload si présent
                if ($form->has('upload')) {
                    $uploaded = $form->get('upload')->getData();
                    if ($uploaded) {
                        $dir = rtrim($this->getParameter('formation_upload_dir'), '/');
                        $filename = $this->fileUploader->upload($uploaded, $dir);
                        $block->setMediaFilename($filename);
                    }
                }

                if ($block->getType() === BlockType::QUIZ && $form->has('quiz')) {
                    if (!$block->getQuiz()) {
                        $block->setQuiz(new Quiz());
                    }
                    $qForm = $form->get('quiz');
                    $settings = [
                        'shuffleQuestions' => (bool)$qForm->get('settingsShuffleQuestions')->getData(),
                        'shuffleChoices'   => (bool)$qForm->get('settingsShuffleChoices')->getData(),
                        'timeLimitSec'     => (int)($qForm->get('settingsTimeLimitSec')->getData() ?? 0),
                    ];
                    $block->getQuiz()?->setSettings($settings);
                } else {
                    $block->setQuiz(null);
                }


                // Sécurité : si jamais ancien block sans createur/entite
                if (method_exists($block, 'getCreateur') && !$block->getCreateur()) {
                    $block->setCreateur($user);
                }
                if (method_exists($block, 'getEntite') && !$block->getEntite()) {
                    $block->setEntite($entite);
                }

                $quiz = $block->getQuiz();
                if ($quiz) {
                    if (method_exists($quiz, 'getCreateur') && !$quiz->getCreateur()) {
                        $quiz->setCreateur($user);
                    }
                    if (method_exists($quiz, 'getEntite') && !$quiz->getEntite()) {
                        $quiz->setEntite($entite);
                    }

                    // Si tu as des questions/choix persistés en cascade :
                    if (method_exists($quiz, 'getQuestions')) {
                        foreach ($quiz->getQuestions() as $q) {
                            if (method_exists($q, 'getCreateur') && !$q->getCreateur()) {
                                $q->setCreateur($user);
                            }
                            if (method_exists($q, 'getEntite') && !$q->getEntite()) {
                                $q->setEntite($entite);
                            }

                            if (method_exists($q, 'getChoices')) {
                                foreach ($q->getChoices() as $c) {
                                    if (method_exists($c, 'getCreateur') && !$c->getCreateur()) {
                                        $c->setCreateur($user);
                                    }
                                    if (method_exists($c, 'getEntite') && !$c->getEntite()) {
                                        $c->setEntite($entite);
                                    }
                                }
                            }
                        }
                    }
                }



                $em->persist($block);
                $em->flush();

                if ($request->isXmlHttpRequest()) {
                    $blocksHtml = $this->renderView('administrateur/formation/content/_blocks_list.html.twig', [
                        'entite'    => $entite,
                        'formation' => $formation,
                        'node'      => $block->getNode(),
                    ]);
                    $editUrl = $this->generateUrl('app_administrateur_formation_content_block_edit', [
                        'entite'    => $entite->getId(),
                        'formation' => $formation->getId(),
                        'node'      => $block->getNode()->getId(),
                        'block'     => $block->getId(),
                    ]);
                    return new JsonResponse([
                        'success'    => true,
                        'blockId'    => $block->getId(),
                        'editUrl'    => $editUrl,
                        'blocksHtml' => $blocksHtml,
                        'message'    => $isEdit ? 'Bloc enregistré.' : 'Bloc créé.',
                    ]);
                }

                $this->addFlash('success', $isEdit ? 'Bloc modifié.' : 'Bloc ajouté.');
                return $this->redirectToRoute('app_administrateur_formation_content_index', [
                    'entite' => $entite->getId(),
                    'formation' => $formation->getId(),
                ]);
            }
        } catch (\Throwable $e) {
            if ($request->isXmlHttpRequest()) {
                // ⚠️ en dev seulement : renvoie l’erreur pour debug
                return new JsonResponse([
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ], 500);
            }
            throw $e; // comportement normal en non-AJAX
        }


        return $this->render('administrateur/formation/content/block_form.html.twig', [
            'entite'    => $entite,
            'formation' => $formation,
            'node'      => $node ?? $block->getNode(),
            'block'     => $block,
            'form'      => $form->createView(),
            'modeEdition' => $isEdit,
            'entite' => $entite,

        ]);
    }

    #[Route('/block/{block}/supprimer', name: 'app_administrateur_formation_content_block_delete', methods: ['POST'])]
    public function blockDelete(Entite $entite, Formation $formation, ContentBlock $block, EntityManagerInterface $em, Request $request): RedirectResponse|JsonResponse
    {


        $node = $block->getNode();
        $em->remove($block);
        $em->flush();

        if ($request->isXmlHttpRequest()) return new JsonResponse(['success' => true]);
        $this->addFlash('success', 'Bloc supprimé.');
        return $this->redirectToRoute('app_administrateur_formation_content_index', [
            'entite' => $entite->getId(),
            'formation' => $formation->getId(),
        ]);
    }

    #[Route('/block/reorder', name: 'app_administrateur_formation_content_block_reorder', methods: ['POST'])]
    public function blockReorder(Entite $entite, Formation $formation, Request $request, EntityManagerInterface $em): JsonResponse
    {


        // Payload: [{id: 5, position:0}, ...]
        $items = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($items)) return new JsonResponse(['success' => false], 400);

        $repo = $em->getRepository(ContentBlock::class);
        foreach ($items as $row) {
            $b = $repo->find((int)($row['id'] ?? 0));
            if (!$b || $b->getNode()?->getFormation()?->getId() !== $formation->getId()) continue;
            $b->setPosition((int)($row['position'] ?? 0));
        }
        $em->flush();
        return new JsonResponse(['success' => true]);
    }
}
