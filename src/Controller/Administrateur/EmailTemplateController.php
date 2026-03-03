<?php

namespace App\Controller\Administrateur;

use App\Entity\{EmailTemplate, Entite, Utilisateur};
use App\Form\Administrateur\EmailTemplateType;
use App\Repository\EmailTemplateRepository;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/emails/templates', name: 'app_administrateur_email_template_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::EMAIL_TEMPLATE_MANAGE, subject: 'entite')]
final class EmailTemplateController extends AbstractController
{
  public function __construct(
    private EM $em,
    private EmailTemplateRepository $repo,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
  ) {}

  #[Route('', name: 'index', methods: ['GET'])]
  public function index(Entite $entite): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $templates = $this->repo->findBy(['entite' => $entite], ['category' => 'ASC', 'name' => 'ASC']);
    return $this->render('administrateur/emails/templates/index.html.twig', [
      'entite' => $entite,
      'templates' => $templates,

    ]);
  }

  #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
  public function new(Entite $entite, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    $t = (new EmailTemplate())->setEntite($entite)->setCategory('prospect')->setCreateur($user);
    $form = $this->createForm(EmailTemplateType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $t->touch();
      $this->em->persist($t);
      $this->em->flush();

      $this->addFlash('success', 'Modèle email créé.');
      return $this->redirectToRoute('app_administrateur_email_template_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/emails/templates/form.html.twig', [
      'entite' => $entite,
      'template' => $t,
      'form' => $form->createView(),
      'modeEdition' => false,

    ]);
  }

  #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
  public function edit(Entite $entite, EmailTemplate $t, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    if ($t->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();

    $form = $this->createForm(EmailTemplateType::class, $t);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $t->touch();
      $this->em->flush();

      $this->addFlash('success', 'Modèle email mis à jour.');
      return $this->redirectToRoute('app_administrateur_email_template_index', [
        'entite' => $entite->getId()
      ]);
    }

    return $this->render('administrateur/emails/templates/form.html.twig', [
      'entite' => $entite,
      'template' => $t,
      'form' => $form->createView(),
      'modeEdition' => true,
    ]);
  }
}
