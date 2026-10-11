<?php

namespace App\Controller\Entreprise;

use App\Entity\Entite;
use App\Entity\Inscription;
use App\Entity\ConventionContrat;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface as EM;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Security\Permission\TenantPermission;
use App\Security\DossierDocumentAccess;
use App\Service\Convention\ConventionPortalSignature;


#[Route('/entreprise/{entite}', name: 'app_entreprise_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::CONVENTION_SIGNATURE_ENTREPRISE_MANAGE, subject: 'entite')]
class ConventionSignatureEntrepriseController extends AbstractController
{
  public function __construct(private EM $em, private ConventionPortalSignature $signatures) {}

  #[Route('/inscription/{id}/convention/signature-entreprise', name: 'convention_sign', methods: ['POST'])]
  public function signByEntreprise(Entite $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();
    DossierDocumentAccess::inscription($entite, $inscription);

    // (Optionnel mais recommandé) sécurité multi-tenant : empêcher de signer une inscription d'une autre entité
    if ($inscription->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Inscription invalide pour cette entité.');
    }

    if (!$this->isCsrfTokenValid('sign_convention_' . $inscription->getId(), (string) $request->request->get('_token'))) {
      throw $this->createAccessDeniedException('Token CSRF invalide.');
    }
    $entreprise = $user->getEntreprise();
    $candidates = $inscription->getConventionContrats()->filter(static fn(ConventionContrat $c) =>
      $entreprise && $c->getEntreprise() === $entreprise && $c->getEntite() === $entite
      && $c->getSession() === $inscription->getSession()
    );
    $conventionId = $request->request->getInt('convention') ?: $request->query->getInt('convention');
    $convention = null;
    foreach ($candidates as $candidate) {
      if ($candidate->getId() === $conventionId || (!$conventionId && count($candidates) === 1)) $convention = $candidate;
    }
    if (!$convention) {
      throw $this->createAccessDeniedException('Choisissez une convention rattachée à votre entreprise et à cette inscription.');
    }

    if ($convention->getDateSignatureEntreprise()) {
      $this->addFlash('info', 'La convention est déjà signée par l’entreprise.');
      return $this->redirectToRoute('app_entreprise_dashboard', [
        'entite' => $entite->getId(),
      ]);
    }

    if (!$convention->getPdfPath()) throw $this->createAccessDeniedException('Générez le document avant signature.');
    try {
      $this->signatures->sign($convention, $user, 'entreprise', null, $request->getSession()->get('convention_reviewed_'.$convention->getId()));
    } catch (\DomainException $error) {
      $this->addFlash('danger', $error->getMessage());
      return $this->redirectToRoute('app_entreprise_dashboard', ['entite' => $entite->getId()]);
    }

    $this->addFlash('success', 'Vous avez signé la convention pour l’entreprise.');
    return $this->redirectToRoute('app_entreprise_dashboard', [
      'entite' => $entite->getId(),
    ]);
  }
}
