<?php
namespace App\Controller;
use App\Entity\{Utilisateur, PersonalSignature};
use App\Service\PersonalSignatureImage;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\{FormType, TextType, PasswordType, RepeatedType};
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountPreferencesController extends AbstractController
{
    #[Route('/compte/preferences/{section}', name: 'app_account_preferences', defaults: ['section' => 'profil'], requirements: ['section' => 'profil|securite|signature'], methods: ['GET', 'POST'])]
    public function preferences(string $section, Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher, TenantContext $tenant, PersonalSignatureImage $images, \Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface $tokens): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $entite = $tenant->getCurrentEntiteForUser($user);
        if (!$entite) return $this->redirectToRoute('app_workspace');
        $signature = $em->getRepository(PersonalSignature::class)->findOneBy(['owner' => $user]);
        $form = null; $error = null;
        if ($section === 'profil') {
            $builder = $this->container->get('form.factory')->createNamedBuilder('profile', FormType::class, ['prenom' => $user->getPrenom(), 'nom' => $user->getNom(), 'telephone' => $user->getTelephone()]);
            foreach (['prenom' => 'Prénom', 'nom' => 'Nom'] as $key => $label) $builder->add($key, TextType::class, ['label' => $label, 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 100)]]);
            $builder->add('telephone', TextType::class, ['label' => 'Téléphone', 'required' => false, 'constraints' => [new Assert\Length(max: 30)]]);
            $form = $builder->getForm()->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData(); $user->setPrenom($data['prenom'])->setNom($data['nom'])->setTelephone($data['telephone']); $em->flush();
                $this->addFlash('success', 'Vos coordonnées ont été enregistrées.');
                return $this->redirectToRoute('app_account_preferences');
            }
        } elseif ($section === 'securite') {
            $form = $this->container->get('form.factory')->createNamedBuilder('password', FormType::class)
                ->add('current', PasswordType::class, ['label' => 'Mot de passe actuel', 'attr' => ['autocomplete' => 'current-password'], 'constraints' => [new Assert\NotBlank(), new UserPassword(message: 'Le mot de passe actuel est incorrect.')]])
                ->add('new', RepeatedType::class, ['type' => PasswordType::class, 'invalid_message' => 'Les mots de passe doivent être identiques.', 'first_options' => ['label' => 'Nouveau mot de passe'], 'second_options' => ['label' => 'Confirmer le mot de passe'], 'options' => ['attr' => ['autocomplete' => 'new-password']], 'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 12, max: 128)]])->getForm()->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $user->setPassword($hasher->hashPassword($user, $form->get('new')->getData())); $em->flush();
                $tokens->setToken(null); $request->getSession()->invalidate();
                return $this->redirectToRoute('app_login');
            }
        } elseif ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('personal_signature', $request->request->getString('_token'))) throw $this->createAccessDeniedException('Formulaire expiré.');
            if ($request->request->getString('action') === 'delete') {
                if ($signature) $em->remove($signature);
            } else {
                try { $normalized = $images->normalize($request->request->getString('image')); }
                catch (\InvalidArgumentException $e) { $error = $e->getMessage(); }
                if (!$error) { $signature ??= new PersonalSignature($user); $signature->update($normalized); $em->persist($signature); }
            }
            if (!$error) { $em->flush(); $this->addFlash('success', 'Votre signature personnelle a été mise à jour.'); return $this->redirectToRoute('app_account_preferences', ['section' => 'signature']); }
        }
        $response = $this->render('account/preferences.html.twig', ['entite' => $entite, 'section' => $section, 'form' => $form?->createView(), 'signature' => $signature, 'error' => $error]);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
