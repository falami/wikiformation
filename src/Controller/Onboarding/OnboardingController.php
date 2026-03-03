<?php

namespace App\Controller\Onboarding;

use App\Entity\{Entite, UtilisateurEntite, Utilisateur};
use App\Entity\Billing\{EntiteSubscription, Plan};
use App\Form\Onboarding\EntiteOnboardingType;
use App\Repository\Billing\EntiteSubscriptionRepository;
use App\Service\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class OnboardingController extends AbstractController
{
  #[Route('/onboarding', name: 'app_onboarding', methods: ['GET', 'POST'])]
  public function index(
    Request $request,
    EntityManagerInterface $em,
    EntiteSubscriptionRepository $subRepo,
    TenantContext $tenant,
    int $billingAppTrialDays = 90,
  ): Response {
    $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

    /** @var Utilisateur|null $user */
    $user = $this->getUser();
    if (!$user instanceof Utilisateur) {
      return $this->redirectToRoute('app_public_home');
    }

    // ✅ Plans depuis la base
    $plans = $em->getRepository(Plan::class)->findBy(
      ['isActive' => true],
      ['ordre' => 'ASC', 'id' => 'ASC']
    );

    $entite = new Entite();
    $form = $this->createForm(EntiteOnboardingType::class, $entite);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {


      /** @var UploadedFile|null $logoFile */
      $logoFile = $form->get('logoFile')->getData();

      if ($logoFile) {
        $slugger = new AsciiSlugger();
        $safe = $slugger->slug(pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME))->lower();
        $ext = $logoFile->guessExtension() ?: $logoFile->getClientOriginalExtension() ?: 'bin';
        $filename = sprintf('logo-%s-%s.%s', $safe, bin2hex(random_bytes(6)), $ext);

        $targetDir = $this->getParameter('logo_entite'); // ton param services.yaml
        $logoFile->move($targetDir, $filename);

        // ✅ on stocke juste le nom de fichier en BDD
        $entite->setLogo($filename);
      }

      // ✅ IMPORTANT : ne pas strtoupper (tes codes sont 'equipe', 'orga', ...)
      $planCode = (string) $form->get('planCode')->getData();
      $interval = (string) $form->get('interval')->getData() ?: 'year';

      if (!in_array($interval, ['month', 'year'], true)) {
        $interval = 'year';
      }

      $plan = null;
      if ($planCode !== '') {
        $plan = $em->getRepository(Plan::class)->findOneBy([
          'code' => $planCode,
          'isActive' => true,
        ]);
      }

      if (!$plan) {
        $form->get('planCode')->addError(new FormError('Choisissez un plan pour continuer.'));
        return $this->render('onboarding/index.html.twig', [
          'form' => $form,
          'trialDays' => $billingAppTrialDays,
          'plans' => $plans,
        ]);
      }

      $conn = $em->getConnection();
      $conn->beginTransaction();

      try {
        // 1) Entite
        $entite->setCreateur($user);
        $entite->setDateCreation(new \DateTimeImmutable());
        $entite->setPublic(false);
        $entite->setIsActive(true);

        // slug provisoire NON NULL
        $entite->setSlug('pending-' . bin2hex(random_bytes(6)));

        // couleurs par défaut
        $entite->setCouleurPrincipal($entite->getCouleurPrincipal() ?: '#233342');
        $entite->setCouleurSecondaire($entite->getCouleurSecondaire() ?: '#0d6efd');
        $entite->setCouleurTertiaire($entite->getCouleurTertiaire() ?: '#F0F0F0');
        $entite->setCouleurQuaternaire($entite->getCouleurQuaternaire() ?: '#0f2336');

        $em->persist($entite);

        // 2) Membership admin
        $ue = new UtilisateurEntite();
        $ue->setUtilisateur($user);
        $ue->setEntite($entite);
        $ue->setRoles([UtilisateurEntite::TENANT_ADMIN]);
        $ue->setCreateur($user);
        $ue->ensureCouleur();
        $em->persist($ue);

        // flush 1 : obtenir ID entite
        $em->flush();

        $entiteId = $entite->getId();
        if (null === $entiteId) {
          throw new \RuntimeException('Impossible de créer l’entité (ID non généré).');
        }

        // slug définitif
        $entite->setSlug('E' . $entiteId);

        // entité courante
        $user->setEntite($entite);

        // flush 2
        $em->flush();

        // 3) Subscription trial
        $existing = $subRepo->findLatestForEntite($entite);
        if (!$existing) {
          $session = $request->getSession();
          $selectedAddons = (array) $session->get('pricing_selected_addons', []);

          $sub = new EntiteSubscription();
          $sub->setEntite($entite);
          $sub->setPlan($plan);
          $sub->setStatus(EntiteSubscription::STATUS_TRIALING);
          $sub->setIntervale($interval);
          $sub->setAddons(array_values(array_map('strval', $selectedAddons)));
          $sub->setTrialEndsAt((new \DateTimeImmutable())->modify('+' . $billingAppTrialDays . ' days'));
          $sub->touch();

          $em->persist($sub);

          // clean session
          $session->remove('pricing_selected_addons');
          $session->remove('pricing_selected_plan');
          $session->remove('pricing_selected_interval');
        }

        $em->flush();

        // 4) Tenant courant
        $tenant->setCurrentEntite($user, $entite);
        $em->flush();

        $conn->commit();
      } catch (\Throwable $e) {
        $conn->rollBack();
        throw $e;
      }

      return $this->redirectToRoute('app_administrateur_billing', [
        'entite' => $entite->getId(),
      ]);
    }

    return $this->render('onboarding/index.html.twig', [
      'form' => $form,
      'trialDays' => $billingAppTrialDays,
      'plans' => $plans,
    ]);
  }
}
