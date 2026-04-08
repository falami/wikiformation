<?php

namespace App\Controller\Administrateur;

use App\Entity\{EntitePreferences, Entite, Utilisateur};
use App\Form\Administrateur\PreferencesContratFormateurType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, JsonResponse};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
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

        $prefs = $this->getOrCreatePreferences($entite, $user);

        $form = $this->createForm(PreferencesContratFormateurType::class, $prefs);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $prefs->setUpdatedBy($user);
            $this->em->flush();

            $this->addFlash('success', 'Préférences de contrat formateur enregistrées.');

            return $this->redirectToRoute('app_administrateur_preferences_formateurs_contrat', [
                'entite' => $entite->getId()
            ]);
        }

        return $this->render('administrateur/preferences/formateurs_contrat.html.twig', [
            'form' => $form->createView(),
            'entite' => $entite,
        ]);
    }

    #[Route('/formateurs/contrat/signature', name: 'formateurs_contrat_signature', methods: ['POST'])]
    public function saveSignatureOrganisme(Entite $entite, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $prefs = $this->getOrCreatePreferences($entite, $user);

        $dataUrl  = trim((string) $request->request->get('dataUrl', ''));
        $nom      = trim((string) $request->request->get('nom', ''));
        $fonction = trim((string) $request->request->get('fonction', ''));

        if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
            return new JsonResponse(['success' => false, 'message' => 'Signature invalide.'], 400);
        }

        $saved = $this->saveBase64Image(
            dataUrl: $dataUrl,
            uploadDirParam: 'contrat_signature_organisme_dir',
            publicPrefixParam: 'contrat_signature_organisme_public_prefix',
            filePrefix: 'signature_entite_' . $entite->getId(),
            oldPublicPath: $prefs->getSignatureOrganismePath(),
        );

        if (!$saved['success']) {
            return new JsonResponse($saved, 400);
        }

        $prefs
            ->setSignatureOrganismePath($saved['publicPath'])
            ->setSignatureOrganismeAt(new \DateTimeImmutable())
            ->setSignatureOrganismeIp((string) ($request->getClientIp() ?? ''))
            ->setSignatureOrganismeUserAgent(substr((string) $request->headers->get('User-Agent', ''), 0, 255))
            ->setSignatureOrganismePar($user)
            ->setSignatureOrganismeSha256($saved['sha256'])
            ->setUpdatedBy($user);

        if ($nom !== '') {
            $prefs->setSignatureOrganismeNom(mb_substr($nom, 0, 120));
        }

        if ($fonction !== '') {
            $prefs->setSignatureOrganismeFonction(mb_substr($fonction, 0, 120));
        }

        $this->em->persist($prefs);
        $this->em->flush();

        $publicUrl = $request->getSchemeAndHttpHost()
            . $request->getBasePath()
            . $saved['publicPath'];

        return new JsonResponse([
            'success'   => true,
            'publicPath'=> $saved['publicPath'],
            'publicUrl' => $publicUrl,
            'at'        => $prefs->getSignatureOrganismeAt()?->format('d/m/Y H:i'),
            'nom'       => $prefs->getSignatureOrganismeNom(),
            'fonction'  => $prefs->getSignatureOrganismeFonction(),
        ]);
    }

    #[Route('/formateurs/contrat/tampon', name: 'formateurs_contrat_tampon', methods: ['POST'])]
    public function saveTamponOrganisme(Entite $entite, Request $request): JsonResponse
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();

        $prefs = $this->getOrCreatePreferences($entite, $user);

        $dataUrl = trim((string) $request->request->get('dataUrl', ''));

        if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) {
            return new JsonResponse(['success' => false, 'message' => 'Tampon invalide.'], 400);
        }

        $saved = $this->saveBase64Image(
            dataUrl: $dataUrl,
            uploadDirParam: 'contrat_tampon_organisme_dir',
            publicPrefixParam: 'contrat_tampon_organisme_public_prefix',
            filePrefix: 'tampon_entite_' . $entite->getId(),
            oldPublicPath: $prefs->getTamponOrganismePath(),
        );

        if (!$saved['success']) {
            return new JsonResponse($saved, 400);
        }

        $prefs
            ->setTamponOrganismePath($saved['publicPath'])
            ->setUpdatedBy($user);

        $this->em->persist($prefs);
        $this->em->flush();

        $publicUrl = $request->getSchemeAndHttpHost()
            . $request->getBasePath()
            . $saved['publicPath'];

        return new JsonResponse([
            'success'    => true,
            'publicPath' => $saved['publicPath'],
            'publicUrl'  => $publicUrl,
        ]);
    }

    private function getOrCreatePreferences(Entite $entite, Utilisateur $user): EntitePreferences
    {
        $prefs = $entite->getPreferences();

        if (!$prefs) {
            $prefs = (new EntitePreferences())
                ->setEntite($entite)
                ->setCreateur($user);

            $entite->setPreferences($prefs);
            $this->em->persist($prefs);
        }

        return $prefs;
    }

    private function saveBase64Image(
        string $dataUrl,
        string $uploadDirParam,
        string $publicPrefixParam,
        string $filePrefix,
        ?string $oldPublicPath = null,
    ): array {
        if (!preg_match('#^data:image/(png|jpeg|jpg);base64,#', $dataUrl, $m)) {
            return ['success' => false, 'message' => 'Format image non supporté (png/jpeg).'];
        }

        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];

        $base64 = preg_replace('#^data:image/(png|jpeg|jpg);base64,#', '', $dataUrl);
        $binary = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < 100) {
            return ['success' => false, 'message' => 'Image illisible.'];
        }

        if (strlen($binary) > 4_000_000) {
            return ['success' => false, 'message' => 'Image trop lourde.'];
        }

        $dir = (string) $this->getParameter($uploadDirParam);
        $prefix = (string) $this->getParameter($publicPrefixParam);

        $fs = new Filesystem();
        $fs->mkdir($dir);

        $rand = ByteString::fromRandom(10)->toString();
        $filename = sprintf(
            '%s_%s_%s.%s',
            $filePrefix,
            (new \DateTimeImmutable())->format('Ymd_His'),
            $rand,
            $ext
        );

        $absolutePath = rtrim($dir, '/') . '/' . $filename;

        if ($oldPublicPath) {
            $oldAbs = str_replace(rtrim($prefix, '/'), rtrim($dir, '/'), $oldPublicPath);
            if (is_file($oldAbs)) {
                @unlink($oldAbs);
            }
        }

        file_put_contents($absolutePath, $binary);

        return [
            'success'    => true,
            'publicPath' => rtrim($prefix, '/') . '/' . $filename,
            'sha256'     => hash_file('sha256', $absolutePath) ?: null,
        ];
    }
}