<?php

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Entity\UtilisateurEntite;
use App\Service\EmailVerifier;
use App\Form\RegistrationFormType;
use App\Service\Email\MailerManager;
use App\Service\Utilisateur\UtilisateurManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        EmailVerifier $emailVerifier,
        MailerManager $mailerManager,
        EntityManagerInterface $em,
        UtilisateurManager $utilisateurManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = new Utilisateur();

        $userPrincipal = $utilisateurManager->getRepository()->find(1);
        if ($userPrincipal) {
            $user->setCreateur($userPrincipal);
        }

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('registration/register.html.twig', [
                'registrationForm' => $form->createView(),
            ]);
        }

        $email = (string) $form->get('email')->getData();

        /** @var Utilisateur|null $existing */
        $existing = $utilisateurManager->getRepository()->findOneBy(['email' => $email]);

        // ===================================
        // CAS A : email déjà en base
        // ===================================
        if ($existing) {
            if ($existing->isVerified()) {
                $this->addFlash('danger', 'Un compte existe déjà avec cette adresse email, veuillez faire une demande de réinitialisation.');
                return $this->redirectToRoute('app_login');
            }

            $user = $existing;

            $plainPassword = (string) $form->get('password')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            $user->setPrenom((string) $form->get('prenom')->getData());
            $user->setNom((string) $form->get('nom')->getData());

            $user->setDateConsentementRgpd(new \DateTimeImmutable());
            $user->setConsentementRgpd(true);
            $user->setNewsletter((bool) $form->get('newsletter')->getData());
            $user->setMailBienvenue(true);

            // Optionnel : sécuriser memberships legacy (roles JSON vides)
            $ueRepo = $em->getRepository(UtilisateurEntite::class);
            $ues = $ueRepo->findBy(['utilisateur' => $user]);

            foreach ($ues as $ue) {
                if (empty($ue->getRoles())) {
                    $ue->setRoles([UtilisateurEntite::TENANT_STAGIAIRE]);
                }
            }

            $em->persist($user);
            $em->flush();

            $emailVerifier->sendEmailConfirmation($user);

            $mailerManager->sendMailContext(
                'no-reply@wikiformation.fr',
                'contact@wikiformation.fr',
                'Inscription d\'un nouveau compte : ' . $user->getEmail(),
                'emails/inscriptionCompte.html.twig',
                ['adherent' => $user]
            );

            $this->addFlash('success', 'Nous venons de vous envoyer un mail pour valider votre adresse mail !');
            return $this->redirectToRoute('app_login');
        }

        // ===================================
        // CAS B : nouvel utilisateur
        // ===================================
        $plainPassword = (string) $form->get('password')->getData();
        $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

        $user->setPrenom((string) $form->get('prenom')->getData());
        $user->setNom((string) $form->get('nom')->getData());

        $user->setDateConsentementRgpd(new \DateTimeImmutable());
        $user->setConsentementRgpd(true);
        $user->setNewsletter((bool) $form->get('newsletter')->getData());

        $user->setIsVerified(false);
        $user->setRoles(['ROLE_USER']);
        $user->setMailBienvenue(true);

        $user->setCouleur($user->getCouleur() ?: '#000000');

        if ($userPrincipal) {
            $user->setCreateur($userPrincipal);
        }

        $em->persist($user);
        $em->flush();

        $emailVerifier->sendEmailConfirmation($user);

        $mailerManager->sendMailContext(
            'no-reply@wikiformation.fr',
            'contact@wikiformation.fr',
            'Création d\'un nouveau compte : ' . $user->getEmail(),
            'emails/creationCompte.html.twig',
            ['adherent' => $user]
        );

        $this->addFlash('success', 'Nous venons de vous envoyer un mail pour valider votre adresse mail !');
        return $this->redirectToRoute('app_login');
    }
}
