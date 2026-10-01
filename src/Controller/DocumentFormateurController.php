<?php

namespace App\Controller;

use App\Entity\{DocumentFormateur, DocumentFormateurVersion, Entite, Utilisateur};
use App\Form\Administrateur\DocumentFormateurType;
use App\Security\Permission\TenantPermission;
use App\Service\Document\DocumentFormateurStorage;
use Doctrine\ORM\{EntityManagerInterface, OptimisticLockException};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request, Response, ResponseHeaderBag};
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class DocumentFormateurController extends AbstractController
{
    public function __construct(private readonly DocumentFormateurStorage $storage) {}

    #[Route('/administrateur/{entite}/documents-formateurs', name: 'app_administrateur_documents_formateurs_index', methods: ['GET'], requirements: ['entite' => '\d+'])]
    #[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
    public function adminIndex(Entite $entite, EntityManagerInterface $em): Response
    {
        return $this->listing($entite, $em, true);
    }

    #[Route('/formateur/{entite}/documents', name: 'app_formateur_documents_index', methods: ['GET'], requirements: ['entite' => '\d+'])]
    public function index(Entite $entite, EntityManagerInterface $em): Response
    {
        $this->assertReader($entite);
        return $this->listing($entite, $em, false);
    }

    private function listing(Entite $entite, EntityManagerInterface $em, bool $manage): Response
    {
        $query = $em->createQueryBuilder()->select('d', 'v')->from(DocumentFormateur::class, 'd')
            ->leftJoin('d.versions', 'v')->where('d.entite = :entite')->setParameter('entite', $entite)
            ->orderBy('d.updatedAt', 'DESC')->addOrderBy('v.numero', 'DESC');
        if (!$manage) $query->andWhere('d.publie = true');
        return $this->render('document_formateur/index.html.twig', [
            'entite' => $entite, 'documents' => $query->getQuery()->getResult(), 'manage' => $manage,
            'categories' => DocumentFormateur::CATEGORIES,
        ]);
    }

    #[Route('/administrateur/{entite}/documents-formateurs/ajouter', name: 'app_administrateur_documents_formateurs_new', methods: ['GET', 'POST'], requirements: ['entite' => '\d+'])]
    #[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
    public function new(Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        return $this->editDocument((new DocumentFormateur())->setEntite($entite), $entite, $request, $em);
    }

    #[Route('/administrateur/{entite}/documents-formateurs/{id}/modifier', name: 'app_administrateur_documents_formateurs_edit', methods: ['GET', 'POST'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    #[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
    public function edit(Entite $entite, DocumentFormateur $document, Request $request, EntityManagerInterface $em): Response
    {
        $this->assertDocument($document, $entite);
        return $this->editDocument($document, $entite, $request, $em);
    }

    private function editDocument(DocumentFormateur $document, Entite $entite, Request $request, EntityManagerInterface $em): Response
    {
        $new = $document->getId() === null;
        $form = $this->createForm(DocumentFormateurType::class, $document, ['new_document' => $new]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$new && (string) $form->get('revision')->getData() !== (string) $document->getLockVersion()) {
                $form->addError(new FormError('Ce document a été modifié entre-temps. Rechargez la page avant de réessayer.'));
            } elseif (!$form->get('file')->getData() && trim((string) $form->get('noteVersion')->getData()) !== '') {
                $form->get('file')->addError(new FormError('Ajoutez le nouveau fichier pour enregistrer cette note de version.'));
            } else {
                $version = null;
                try {
                    $file = $form->get('file')->getData();
                    if ($file) {
                        /** @var Utilisateur $user */
                        $user = $this->getUser();
                        $version = $this->storage->store($file, $user, ($document->getCurrentVersion()?->getNumero() ?? 0) + 1, $form->get('noteVersion')->getData());
                        $document->addVersion($version);
                    }
                    $document->touch();
                    $em->persist($document);
                    $em->flush();
                    $this->addFlash('success', $document->isPublie() ? 'Document enregistré et disponible pour les formateurs.' : 'Document enregistré. Il est masqué dans l’espace formateur.');
                    return $this->redirectToRoute('app_administrateur_documents_formateurs_index', ['entite' => $entite->getId()]);
                } catch (FileException) {
                    $form->get('file')->addError(new FormError('Le fichier n’a pas pu être enregistré. Réessayez ou contactez votre administrateur.'));
                } catch (OptimisticLockException) {
                    if ($version) $this->storage->discard($version);
                    $form->addError(new FormError('Une autre mise à jour vient d’être enregistrée. Rechargez la page avant de réessayer.'));
                } catch (\Throwable $error) {
                    if ($version) $this->storage->discard($version);
                    throw $error;
                }
            }
        }
        return $this->render('document_formateur/form.html.twig', ['entite' => $entite, 'document' => $document, 'form' => $form->createView(), 'new' => $new]);
    }

    #[Route('/formateur/{entite}/documents/{id}/telecharger', name: 'app_formateur_documents_download', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+'])]
    public function download(Entite $entite, DocumentFormateur $document): Response
    {
        $this->assertReader($entite);
        $this->assertDocument($document, $entite);
        if (!$document->isPublie() && !$this->isGranted(TenantPermission::FORMATEUR_MANAGE, $entite)) throw $this->createNotFoundException();
        $version = $document->getCurrentVersion();
        if (!$version) throw $this->createNotFoundException('Aucun fichier disponible.');
        return $this->fileResponse($version);
    }

    #[Route('/administrateur/{entite}/documents-formateurs/{id}/versions/{version}', name: 'app_administrateur_documents_formateurs_version', methods: ['GET'], requirements: ['entite' => '\d+', 'id' => '\d+', 'version' => '\d+'])]
    #[IsGranted(TenantPermission::FORMATEUR_MANAGE, subject: 'entite')]
    public function version(Entite $entite, DocumentFormateur $document, int $version): Response
    {
        $this->assertDocument($document, $entite);
        foreach ($document->getVersions() as $file) {
            if ($file->getNumero() === $version) return $this->fileResponse($file);
        }
        throw $this->createNotFoundException();
    }

    private function fileResponse(DocumentFormateurVersion $version): BinaryFileResponse
    {
        $path = $this->storage->path($version);
        if (!is_file($path)) throw $this->createNotFoundException('Ce fichier n’est plus disponible. Contactez votre organisme.');
        $response = $this->file($path, $version->getOriginalName(), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    private function assertReader(Entite $entite): void
    {
        if (!$this->isGranted(TenantPermission::FORMATEUR, $entite) && !$this->isGranted(TenantPermission::FORMATEUR_MANAGE, $entite)) throw $this->createAccessDeniedException();
    }

    private function assertDocument(DocumentFormateur $document, Entite $entite): void
    {
        if ($document->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
    }
}
