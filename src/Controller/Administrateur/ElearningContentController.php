<?php
// src/Controller/Administrateur/ElearningContentAdminController.php

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Quiz;
use App\Enum\BlockType;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningNode;
use App\Entity\Elearning\ElearningBlock;
use App\Form\Administrateur\ElearningNodeType;
use App\Form\Administrateur\ElearningBlockType;
use App\Service\FileUploader;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse, RedirectResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Enum\NodeType;
use App\Repository\Elearning\ElearningNodeRepository;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/elearning/{course}/contenu', name: 'app_administrateur_elearning_content_')]
#[IsGranted(TenantPermission::ELEARNING_CONTENT_MANAGE, subject: 'entite')]
final class ElearningContentController extends AbstractController
{
  public function __construct(
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private FileUploader $fileUploader,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite, ElearningCourse $course, EntityManagerInterface $em): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    $roots = $em->getRepository(ElearningNode::class)->findBy(
      ['course' => $course, 'parent' => null],
      ['position' => 'ASC', 'id' => 'ASC']
    );

    return $this->render('administrateur/elearning/content/index.html.twig', [
      'entite' => $entite,
      'course' => $course,
      'roots'  => $roots,


    ]);
  }

  #[Route('/node/ajouter', name: 'node_add', methods: ['GET', 'POST'])]
  #[Route('/node/{node}/modifier', name: 'node_edit', methods: ['GET', 'POST'])]
  public function nodeAddEdit(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em,
    ?ElearningNode $node = null
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $isEdit = (bool) $node;

    $node ??= (new ElearningNode())
      ->setCourse($course)
      ->setCreateur($user)
      ->setEntite($entite);

    // parentId optionnel (création sous-chapitre)


    $parentId = $request->query->getInt('parentId', 0);
    if (!$isEdit && $parentId > 0) {
      $parent = $em->getRepository(ElearningNode::class)->find($parentId);

      if ($parent && $parent->getCourse()?->getId() === $course->getId()) {
        $node->setParent($parent);

        // ✅ AUTO: sous-chapitre déjà sélectionné
        $node->setType(NodeType::SOUS_CHAPITRE);

        // (optionnel) pré-remplir le titre
        // $node->setTitre('Sous-chapitre de ' . $parent->getTitre());
      }
    }

    $lockParent = (!$isEdit && $parentId > 0 && $node->getParent());

    $form = $this->createForm(ElearningNodeType::class, $node, [
      'lock_parent' => $lockParent,
    ])->handleRequest($request);


    if ($form->isSubmitted() && !$form->isValid()) {
      foreach ($form->getErrors(true) as $e) {
        $this->addFlash('danger', $e->getMessage());
      }
    }


    if ($form->isSubmitted()) {
      // 1) slug auto AVANT validation
      $slug = trim((string) $node->getSlug());
      if ($slug === '') {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT', $node->getTitre());
        $base = preg_replace('/[^a-z0-9]+/i', '-', (string) $base);
        $base = strtolower(trim((string) $base, '-'));
        $node->setSlug($base);
      }

  // 2) si pris => suffixe -2, -3... (AVANT validation aussi, car contrainte unique DB)
      /** @var ElearningNodeRepository $nodeRepo */
      $nodeRepo = $em->getRepository(ElearningNode::class);
      $base = $node->getSlug();

      if ($nodeRepo->slugExistsForCourse((int)$course->getId(), $base, $node->getId())) {
        $i = 2;
        do {
          $candidate = $base . '-' . $i;
          $i++;
          if ($i > 200) break;
        } while ($nodeRepo->slugExistsForCourse((int)$course->getId(), $candidate, $node->getId()));

        $node->setSlug($candidate);
        $this->addFlash('warning', sprintf('Slug déjà pris, remplacé par "%s".', $candidate));
      }
    }


    if ($form->isSubmitted()) {
      $pid = (int) ($form->has('parentIdLock') ? $form->get('parentIdLock')->getData() : 0);

      if (!$node->getParent() && $pid > 0) {
        $parent = $em->getRepository(ElearningNode::class)->find($pid);
        if ($parent && $parent->getCourse()?->getId() === $course->getId()) {
          $node->setParent($parent);
          $node->setType(NodeType::SOUS_CHAPITRE);
        }
      }
    }


    if ($form->isSubmitted() && $form->isValid()) {
      // garde-fou édition
      if ($isEdit && $node->getCourse()?->getId() !== $course->getId()) {
        throw $this->createAccessDeniedException();
      }

      // comme formation : updatedAt
      if (method_exists($node, 'setUpdatedAt')) {
        $node->setUpdatedAt(new \DateTime());
      }



      /** @var ElearningNodeRepository $nodeRepo */
      $nodeRepo = $em->getRepository(ElearningNode::class);

      $slug = trim($node->getSlug() ?? '');
      if ($slug === '') {
        // si vide, auto depuis titre
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $node->getTitre())), '-'));
        $node->setSlug($slug);
      }

      // si pris => suffixe -2, -3
      $base = $slug;
      if ($nodeRepo->slugExistsForCourse((int)$course->getId(), $base, $node->getId())) {
        $i = 2;
        do {
          $candidate = $base . '-' . $i;
          $i++;
          if ($i > 200) break;
        } while ($nodeRepo->slugExistsForCourse((int)$course->getId(), $candidate, $node->getId()));
        $node->setSlug($candidate);
        $this->addFlash('warning', sprintf('Slug déjà pris, remplacé par "%s".', $candidate));
      }

      $em->persist($node);
      $em->flush();

      $this->addFlash('success', $isEdit ? 'Chapitre modifié.' : 'Chapitre ajouté.');
      return $this->redirectToRoute('app_administrateur_elearning_content_index', [
        'entite' => $entite->getId(),
        'course' => $course->getId(),
      ]);
    }

    $roots = $em->getRepository(ElearningNode::class)->findBy(
      ['course' => $course, 'parent' => null],
      ['position' => 'ASC', 'id' => 'ASC']
    );


    return $this->render('administrateur/elearning/content/node_form.html.twig', [
      'entite' => $entite,
      'course' => $course,
      'node' => $node,
      'roots' => $roots,
      'form' => $form->createView(),
      'modeEdition' => $isEdit,


    ]);
  }

  #[Route('/node/{node}/supprimer', name: 'node_delete', methods: ['POST'])]
  public function nodeDelete(
    Entite $entite,
    ElearningCourse $course,
    ElearningNode $node,
    EntityManagerInterface $em,
    Request $request
  ): RedirectResponse|JsonResponse {
    // CSRF optionnel, comme formation (tu peux garder ton token si tu veux)
    // if (!$this->isCsrfTokenValid('del_node_'.$node->getId(), (string) $request->request->get('_token'))) { ... }

    if ($node->getCourse()?->getId() !== $course->getId()) {
      throw $this->createAccessDeniedException();
    }

    $em->remove($node);
    $em->flush();

    if ($request->isXmlHttpRequest()) return new JsonResponse(['success' => true]);

    $this->addFlash('success', 'Chapitre supprimé.');
    return $this->redirectToRoute('app_administrateur_elearning_content_index', [
      'entite' => $entite->getId(),
      'course' => $course->getId(),
    ]);
  }

  #[Route('/node/reorder', name: 'node_reorder', methods: ['POST'])]
  public function nodeReorder(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em
  ): JsonResponse {
    // Payload attendu: [{id: 12, position:0, parentId:null}, ...]
    $items = json_decode($request->getContent() ?: '[]', true);
    if (!\is_array($items)) {
      return new JsonResponse(['success' => false, 'message' => 'Payload invalide'], 400);
    }

    $repo = $em->getRepository(ElearningNode::class);

    foreach ($items as $row) {
      /** @var ElearningNode|null $node */
      $node = $repo->find((int)($row['id'] ?? 0));
      if (!$node || $node->getCourse()?->getId() !== $course->getId()) continue;

      $node->setPosition((int)($row['position'] ?? 0));

      $parentId = $row['parentId'] ?? null;
      if ($parentId) {
        $parent = $repo->find((int)$parentId);
        if ($parent && $parent->getCourse()?->getId() === $course->getId()) {
          $node->setParent($parent);
        }
      } else {
        $node->setParent(null);
      }

      if (method_exists($node, 'setUpdatedAt')) {
        $node->setUpdatedAt(new \DateTime());
      }
    }

    $em->flush();
    return new JsonResponse(['success' => true]);
  }

  #[Route('/node/{node}/block/ajouter', name: 'block_add', methods: ['GET', 'POST'])]
  #[Route('/block/{block}/modifier', name: 'block_edit', methods: ['GET', 'POST'])]
  public function blockAddEdit(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em,
    ?ElearningNode $node = null,
    ?ElearningBlock $block = null
  ): Response {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $isEdit = (bool) $block;

    // 1) EDIT route mais bloc absent => redirection propre (copie formation)
    if ($request->attributes->get('_route') === 'app_administrateur_elearning_content_block_edit' && !$block) {
      $nodeIdFromQuery = $request->query->getInt('node', 0);
      if ($nodeIdFromQuery > 0) {
        $node = $em->getRepository(ElearningNode::class)->find($nodeIdFromQuery);
        if ($node && $node->getCourse()?->getId() === $course->getId()) {
          $this->addFlash('warning', 'Ce bloc n’existe plus (il a peut-être été supprimé).');
          return $this->redirectToRoute('app_administrateur_elearning_content_block_add', [
            'entite' => $entite->getId(),
            'course' => $course->getId(),
            'node'   => $node->getId(),
          ]);
        }
      }

      $this->addFlash('warning', 'Ce bloc n’existe plus.');
      return $this->redirectToRoute('app_administrateur_elearning_content_index', [
        'entite' => $entite->getId(),
        'course' => $course->getId(),
      ]);
    }

    // 2) création : si on n'a pas $node (ex: route block_add via query ?node=)
    if (!$block && !$node) {
      $nodeIdFromQuery = $request->query->getInt('node', 0);
      if ($nodeIdFromQuery > 0) {
        $candidate = $em->getRepository(ElearningNode::class)->find($nodeIdFromQuery);
        if ($candidate && $candidate->getCourse()?->getId() === $course->getId()) {
          $node = $candidate;
        }
      }
    }

    // Création / édition (copie formation)
    if (!$isEdit) {
      if (!$node || $node->getCourse()?->getId() !== $course->getId()) {
        throw $this->createNotFoundException();
      }
      $block = (new ElearningBlock())
        ->setNode($node)
        ->setCreateur($user)
        ->setEntite($entite);
    } else {
      if ($block?->getNode()?->getCourse()?->getId() !== $course->getId()) {
        throw $this->createAccessDeniedException();
      }
    }

    $form = $this->createForm(ElearningBlockType::class, $block, [
      'createur' => $this->getUser(),
      'entite'   => $entite,
    ])->handleRequest($request);

    // 422 AJAX si invalide (copie formation)
    if ($form->isSubmitted() && !$form->isValid() && $request->isXmlHttpRequest()) {
      $formHtml = $this->renderView('administrateur/elearning/content/block_form.html.twig', [
        'entite' => $entite,
        'course' => $course,
        'node'   => $node ?? $block->getNode(),
        'block'  => $block,
        'form'   => $form->createView(),
        'modeEdition' => $isEdit,

      ]);

      return new JsonResponse(['success' => false, 'formHtml' => $formHtml], 422);
    }

    try {
      if ($form->isSubmitted() && $form->isValid()) {

        // upload si présent
        if ($form->has('upload')) {
          $uploaded = $form->get('upload')->getData();
          if ($uploaded) {
            $dir = rtrim((string) $this->getParameter('elearning_upload_dir'), '/');
            $filename = $this->fileUploader->upload($uploaded, $dir);
            $block->setMediaFilename($filename);
          }
        }

        // quiz settings (copie formation)
        if ($block->getType() === BlockType::QUIZ && $form->has('quiz')) {
          if (!$block->getQuiz()) {
            $quiz = new Quiz();
            $quiz->setCreateur($user);
            $quiz->setEntite($entite);
            $block->setQuiz($quiz);
          }
          $qForm = $form->get('quiz');
          $settings = [
            'shuffleQuestions' => (bool) $qForm->get('settingsShuffleQuestions')->getData(),
            'shuffleChoices'   => (bool) $qForm->get('settingsShuffleChoices')->getData(),
            'timeLimitSec'     => (int) ($qForm->get('settingsTimeLimitSec')->getData() ?? 0),
          ];
          $block->getQuiz()?->setSettings($settings);
        } else {
          $block->setQuiz(null);
        }

        // Sécurité createur/entite (copie formation)
        if (method_exists($block, 'getCreateur') && !$block->getCreateur()) {
          $block->setCreateur($user);
        }
        if (method_exists($block, 'getEntite') && !$block->getEntite()) {
          $block->setEntite($entite);
        }

        // sécurise quiz (si ton Quiz a entite/createur)
        $quiz = $block->getQuiz();
        if ($quiz) {
          if (method_exists($quiz, 'getCreateur') && !$quiz->getCreateur()) $quiz->setCreateur($user);
          if (method_exists($quiz, 'getEntite') && !$quiz->getEntite())   $quiz->setEntite($entite);

          if (method_exists($quiz, 'getQuestions')) {
            foreach ($quiz->getQuestions() as $q) {
              if (method_exists($q, 'getCreateur') && !$q->getCreateur()) $q->setCreateur($user);
              if (method_exists($q, 'getEntite') && !$q->getEntite())     $q->setEntite($entite);

              if (method_exists($q, 'getChoices')) {
                foreach ($q->getChoices() as $c) {
                  if (method_exists($c, 'getCreateur') && !$c->getCreateur()) $c->setCreateur($user);
                  if (method_exists($c, 'getEntite') && !$c->getEntite())     $c->setEntite($entite);
                }
              }
            }
          }
        }

        $em->persist($block);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
          $blocksHtml = $this->renderView('administrateur/elearning/content/_blocks_list.html.twig', [
            'entite' => $entite,
            'course' => $course,
            'node'   => $block->getNode(),
          ]);

          $editUrl = $this->generateUrl('app_administrateur_elearning_content_block_edit', [
            'entite' => $entite->getId(),
            'course' => $course->getId(),
            'node'   => $block->getNode(),
            'block'  => $block->getId(),
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
        return $this->redirectToRoute('app_administrateur_elearning_content_index', [
          'entite' => $entite->getId(),
          'course' => $course->getId(),
        ]);
      }
    } catch (\Throwable $e) {
      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => false,
          'error'   => $e->getMessage(),
          'trace'   => $e->getTraceAsString(),
        ], 500);
      }
      throw $e;
    }

    return $this->render('administrateur/elearning/content/block_form.html.twig', [
      'entite' => $entite,
      'course' => $course,
      'node'   => $node ?? $block->getNode(),
      'block'  => $block,
      'form'   => $form->createView(),
      'modeEdition' => $isEdit,


    ]);
  }

  #[Route('/block/{block}/supprimer', name: 'block_delete', methods: ['POST'])]
  public function blockDelete(
    Entite $entite,
    ElearningCourse $course,
    ElearningBlock $block,
    EntityManagerInterface $em,
    Request $request
  ): RedirectResponse|JsonResponse {
    if ($block->getNode()?->getCourse()?->getId() !== $course->getId()) {
      throw $this->createAccessDeniedException();
    }

    $em->remove($block);
    $em->flush();

    if ($request->isXmlHttpRequest()) return new JsonResponse(['success' => true]);

    $this->addFlash('success', 'Bloc supprimé.');
    return $this->redirectToRoute('app_administrateur_elearning_content_index', [
      'entite' => $entite->getId(),
      'course' => $course->getId(),
    ]);
  }

  #[Route('/block/reorder', name: 'block_reorder', methods: ['POST'])]
  public function blockReorder(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    EntityManagerInterface $em
  ): JsonResponse {
    $items = json_decode($request->getContent() ?: '[]', true);
    if (!\is_array($items)) return new JsonResponse(['success' => false], 400);

    $repo = $em->getRepository(ElearningBlock::class);
    foreach ($items as $row) {
      /** @var ElearningBlock|null $b */
      $b = $repo->find((int)($row['id'] ?? 0));
      if (!$b || $b->getNode()?->getCourse()?->getId() !== $course->getId()) continue;

      $b->setPosition((int)($row['position'] ?? 0));
    }

    $em->flush();
    return new JsonResponse(['success' => true]);
  }

  #[Route('/node/slug-check', name: 'node_slug_check', methods: ['GET'])]
  public function nodeSlugCheck(
    Entite $entite,
    ElearningCourse $course,
    Request $request,
    ElearningNodeRepository $repo
  ): JsonResponse {

    $slug = trim((string) $request->query->get('slug', ''));
    $excludeId = $request->query->getInt('excludeId') ?: null;

    if ($slug === '') {
      return $this->json([
        'ok' => true,
        'available' => false,
        'message' => 'Slug vide',
        'suggestion' => null,
      ]);
    }

    $base = $slug;
    $exists = $repo->slugExistsForCourse((int) $course->getId(), $base, $excludeId);

    $suggestion = $base;
    if ($exists) {
      $i = 2;
      do {
        $suggestion = $base . '-' . $i;
        $i++;
        if ($i > 200) break;
      } while ($repo->slugExistsForCourse((int) $course->getId(), $suggestion, $excludeId));
    }

    return $this->json([
      'ok' => true,
      'available' => !$exists,
      'message' => !$exists ? 'Disponible' : 'Déjà pris',
      'suggestion' => $suggestion,
    ]);
  }
}
