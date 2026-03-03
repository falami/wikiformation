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


#[Route('/entreprise/{entite}', name: 'app_entreprise_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::CONVENTION_SIGNATURE_ENTREPRISE_MANAGE, subject: 'entite')]
class ConventionSignatureEntrepriseController extends AbstractController
{
  public function __construct(private EM $em) {}

  #[Route('/inscription/{id}/convention/signature-entreprise', name: 'convention_sign', methods: ['POST'])]
  public function signByEntreprise(Entite $entite, Inscription $inscription, Request $request): Response
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // (Optionnel mais recommandé) sécurité multi-tenant : empêcher de signer une inscription d'une autre entité
    if ($inscription->getEntite()?->getId() !== $entite->getId()) {
      throw $this->createAccessDeniedException('Inscription invalide pour cette entité.');
    }

        // ✅ Comme tu as une ManyToMany, on récupère une convention depuis la collection
    /** @var ConventionContrat|null $convention */
    $convention = $inscription->getConventionContrats()->first() ?: null;

    if (!$convention) {
      $this->addFlash('danger', 'Aucune convention associée à cette inscription.');
      return $this->redirectToRoute('app_entreprise_inscription_show', [
        'entite' => $entite,
        'id'     => $inscription,
      ]);
    }

    if ($convention->getDateSignatureEntreprise()) {
      $this->addFlash('info', 'La convention est déjà signée par l’entreprise.');
      return $this->redirectToRoute('app_entreprise_inscription_show', [
        'entite' => $entite->getId(),
        'id'     => $inscription->getId(),
      ]);
    }

    $convention->setDateSignatureEntreprise(new \DateTimeImmutable());
    $this->em->flush();

    $this->addFlash('success', 'Vous avez signé la convention pour l’entreprise.');
    return $this->redirectToRoute('app_entreprise_inscription_show', [
      'entite' => $entite->getId(),
      'id'     => $inscription->getId(),
    ]);
  }
}
