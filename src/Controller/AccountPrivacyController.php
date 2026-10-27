<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\Privacy\DataRightsRequest;
use App\Service\Privacy\AccountDataExport;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{FormType, ChoiceType, TextareaType};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountPrivacyController extends AbstractController
{
    #[Route('/compte/confidentialite', name: 'app_account_privacy', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, TenantContext $tenant): Response
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) throw $this->createAccessDeniedException();
        $form = $this->container->get('form.factory')->createNamedBuilder('privacy_request', FormType::class)
            ->add('type', ChoiceType::class, ['label' => 'Droit à exercer', 'choices' => array_flip(DataRightsRequest::TYPES), 'placeholder' => 'Choisir une demande', 'constraints' => [new Assert\NotBlank()]])
            ->add('details', TextareaType::class, ['label' => 'Précisions (facultatif)', 'required' => false, 'help' => 'Indiquez les organismes ou données concernés. Ne joignez ni mot de passe, ni copie de pièce d’identité, ni donnée de santé.', 'attr' => ['rows' => 4, 'maxlength' => 2000], 'constraints' => [new Assert\Length(max: 2000)]])
            ->getForm()->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            // Repeat submissions share the same open request; this is not a refusal of additional rights.
            $existing = $em->getRepository(DataRightsRequest::class)->findOneBy(['owner' => $user, 'type' => $data['type'], 'details' => trim($data['details'] ?? ''), 'status' => ['pending', 'processing']]);
            $submission = $existing ?? new DataRightsRequest($user, $data['type'], $data['details'] ?? '');
            if (!$existing) { $em->persist($submission); $em->flush(); }
            $this->addFlash('success', 'Votre demande '.$submission->getReference().' est enregistrée. Suivez sa réponse dans cet espace.');
            return $this->privateResponse($this->redirectToRoute('app_account_privacy'));
        }
        return $this->privateResponse($this->render('account/privacy.html.twig', [
            'entite' => $tenant->getCurrentEntiteForUser($user), 'section' => 'confidentialite', 'form' => $form->createView(),
            'requests' => $em->getRepository(DataRightsRequest::class)->findBy(['owner' => $user], ['requestedAt' => 'DESC']),
        ]));
    }

    #[Route('/compte/confidentialite/export', name: 'app_account_privacy_export', methods: ['POST'])]
    public function export(Request $request, AccountDataExport $export): Response
    {
        $this->csrf($request, 'account_privacy_export');
        $response = $this->json($export->export($this->getUser()));
        $response->headers->set('Content-Disposition', 'attachment; filename="mes-donnees-compte.json"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $this->privateResponse($response);
    }

    #[Route('/compte/confidentialite/newsletter/retrait', name: 'app_account_privacy_newsletter_withdraw', methods: ['POST'])]
    public function withdrawNewsletter(Request $request, EntityManagerInterface $em): Response
    {
        $this->csrf($request, 'account_privacy_newsletter');
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $user->setNewsletter(false);
        $em->flush();
        $this->addFlash('success', 'Votre préférence newsletter a été désactivée. Les e-mails nécessaires au fonctionnement de vos formations restent distincts.');
        return $this->privateResponse($this->redirectToRoute('app_account_privacy'));
    }

    private function csrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) throw $this->createAccessDeniedException('Rechargez la page puis réessayez.');
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
