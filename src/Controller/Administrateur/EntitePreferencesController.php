<?php

namespace App\Controller\Administrateur;

use App\Entity\{EntitePreferences, Entite, Utilisateur};
use App\Form\Administrateur\PreferencesContratFormateurType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\ByteString;
use Symfony\Component\Asset\Packages;
use App\Security\Permission\TenantPermission;


#[Route('/administrateur/{entite}/preferences', name: 'app_administrateur_preferences_', requirements: ['entite' => '\d+'])]
#[IsGranted(TenantPermission::ENTITE_PREFERENCE_MANAGE, subject: 'entite')]
class EntitePreferencesController extends AbstractController
{
  public function __construct(
    private EntityManagerInterface $em,
    private UtilisateurEntiteManager $utilisateurEntiteManager,
    private Packages $assets,
  ) {}

  #[Route('/formateurs/contrat', name: 'formateurs_contrat', methods: ['GET', 'POST'])]
  public function formateursContrat(Entite $entite, Request $request): Response
  {

    /** @var Utilisateur $user */
    $user = $this->getUser();
    // récupère ou crée les prefs
    $prefs = $entite->getPreferences();
    if (!$prefs) {
      $prefs = (new EntitePreferences())->setEntite($entite)->setCreateur($user);
      $entite->setPreferences($prefs);
      $this->em->persist($prefs);
    }

    $form = $this->createForm(PreferencesContratFormateurType::class, $prefs);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {
      $this->em->flush();
      $this->addFlash('success', 'Préférences de contrat formateur enregistrées.');
      return $this->redirectToRoute('app_administrateur_preferences_formateurs_contrat', ['entite' => $entite->getId()]);
    }

    return $this->render('administrateur/preferences/formateurs_contrat.html.twig', [
      'entite' => $entite,
      'form' => $form->createView(),
      'entite' => $entite,
    ]);
  }


  #[Route('/formateurs/contrat/signature', name: 'formateurs_contrat_signature', methods: ['POST'])]
  public function saveSignatureOrganisme(Entite $entite, Request $request): JsonResponse
  {
    /** @var Utilisateur $user */
    $user = $this->getUser();

    // récupère ou crée les prefs
    $prefs = $entite->getPreferences();
    if (!$prefs) {
      $prefs = (new EntitePreferences())
        ->setEntite($entite)
        ->setCreateur($user);
      $entite->setPreferences($prefs);
      $this->em->persist($prefs);
    }

    $dataUrl  = trim((string)$request->request->get('dataUrl', ''));
    $nom      = trim((string)$request->request->get('nom', ''));
    $fonction = trim((string)$request->request->get('fonction', ''));

    if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
      return new JsonResponse(['success' => false, 'message' => 'Signature invalide.'], 400);
    }

    // accepte png / jpeg
    if (!preg_match('#^data:image/(png|jpeg);base64,#', $dataUrl, $m)) {
      return new JsonResponse(['success' => false, 'message' => 'Format signature non supporté (png/jpeg).'], 400);
    }
    $ext = $m[1] === 'jpeg' ? 'jpg' : 'png';

    $base64 = preg_replace('#^data:image/(png|jpeg);base64,#', '', $dataUrl);
    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) < 100) {
      return new JsonResponse(['success' => false, 'message' => 'Signature illisible.'], 400);
    }

    // (Optionnel) sécurité : limite taille ~ 2MB
    if (strlen($binary) > 2_000_000) {
      return new JsonResponse(['success' => false, 'message' => 'Signature trop lourde.'], 400);
    }

    $dir = (string)$this->getParameter('contrat_signature_organisme_dir');
    $prefix = (string)$this->getParameter('contrat_signature_organisme_public_prefix');

    $fs = new Filesystem();
    $fs->mkdir($dir);

    // nom fichier robuste
    $rand = ByteString::fromRandom(10)->toString();
    $filename = sprintf('entite_%d_%s_%s.%s', $entite->getId(), (new \DateTimeImmutable())->format('Ymd_His'), $rand, $ext);
    $absolutePath = rtrim($dir, '/') . '/' . $filename;

    // si une ancienne signature existe, tu peux la supprimer (optionnel mais propre)
    $oldPublic = $prefs->getSignatureOrganismePath();
    if ($oldPublic) {
      $oldAbs = str_replace($prefix, rtrim($dir, '/'), $oldPublic);
      if (is_file($oldAbs)) {
        @unlink($oldAbs);
      }
    }

    file_put_contents($absolutePath, $binary);

    $sha256 = hash_file('sha256', $absolutePath) ?: null;
    $publicPath = rtrim($prefix, '/') . '/' . $filename;

    $prefs
      ->setSignatureOrganismePath($publicPath)
      ->setSignatureOrganismeAt(new \DateTimeImmutable())
      ->setSignatureOrganismeIp((string)($request->getClientIp() ?? ''))
      ->setSignatureOrganismeUserAgent(substr((string)$request->headers->get('User-Agent', ''), 0, 255))
      ->setSignatureOrganismePar($user)
      ->setSignatureOrganismeSha256($sha256);

    // identité affichée dans le PDF
    if ($nom !== '') {
      $prefs->setSignatureOrganismeNom(mb_substr($nom, 0, 120));
    }
    if ($fonction !== '') {
      $prefs->setSignatureOrganismeFonction(mb_substr($fonction, 0, 120));
    }

    // si tu utilises updatedBy dans EntitePreferences
    $prefs->setUpdatedBy($user);

    $this->em->persist($prefs);
    $this->em->flush();

    $publicUrl = $request->getSchemeAndHttpHost()
      . $request->getBasePath()
      . $publicPath;

    return new JsonResponse([
      'success' => true,
      'publicPath' => $publicPath,
      'publicUrl' => $publicUrl,
      'at' => $prefs->getSignatureOrganismeAt()?->format('d/m/Y H:i'),
      'nom' => $prefs->getSignatureOrganismeNom(),
      'fonction' => $prefs->getSignatureOrganismeFonction(),
    ]);
  }
}
