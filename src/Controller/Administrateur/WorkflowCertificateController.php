<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Automation\TrainingWorkflow;
use App\Entity\{Entite, Inscription, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Automation\WorkflowCertificateStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request, Response, ResponseHeaderBag};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

final class WorkflowCertificateController extends AbstractController
{
    #[Route('/administrateur/{entite}/parcours/{id}/certificat', name: 'app_administrateur_workflow_certificate', requirements: ['entite'=>'\d+', 'id'=>'\d+'], methods: ['POST'])]
    public function upload(Entite $entite, TrainingWorkflow $workflow, Request $request, EntityManagerInterface $em, WorkflowCertificateStorage $storage): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::SESSION_MANAGE, $entite);
        if ($workflow->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('workflow_certificate_'.$workflow->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $inscription = $em->getRepository(Inscription::class)->find($request->request->getInt('inscription'));
        $this->assertInscription($workflow, $inscription);
        $file = $request->files->get('certificate');
        $issued = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('issuedOn'));
        $actor = $this->getUser();
        if (!$actor instanceof Utilisateur) throw $this->createAccessDeniedException();
        if (!$file instanceof UploadedFile || !$issued || $issued->format('Y-m-d') !== $request->request->getString('issuedOn') || $request->request->getString('habilitation') !== '1') {
            $this->addFlash('warning', 'Ajoutez le PDF, sa date de délivrance et confirmez la validation par une personne habilitée.');
            return $this->back($entite, $workflow);
        }
        $written = null;
        try {
            $em->wrapInTransaction(function () use ($em, $workflow, $inscription, $file, $actor, $issued, $request, $storage, &$written): void {
                $em->refresh($workflow, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($inscription, LockMode::PESSIMISTIC_WRITE);
                $this->assertInscription($workflow, $inscription);
                $existing = $inscription->getMeta()['workflowCertificate'] ?? null;
                if ($existing && ($request->request->getString('replace') !== '1' || (int)($existing['version'] ?? 0) !== $request->request->getInt('version'))) throw new \DomainException('Un certificat est déjà enregistré ou a été remplacé. Rechargez la page et confirmez son remplacement.');
                $written = $storage->store($file, $workflow, $inscription, $actor, $issued, $request->request->getString('reason'));
            });
            $this->addFlash('success', 'Le certificat validé est conservé dans le dossier. Son envoi suit les étapes du parcours.');
        } catch (\Throwable $e) {
            if ($written && is_file($written)) unlink($written);
            if (!$e instanceof \DomainException) throw $e;
            $this->addFlash('warning', $e->getMessage());
        }
        return $this->back($entite, $workflow);
    }

    #[Route('/administrateur/{entite}/parcours/{id}/certificat/{inscription}/{version}', name: 'app_administrateur_workflow_certificate_download', requirements: ['entite'=>'\d+', 'id'=>'\d+', 'inscription'=>'\d+', 'version'=>'\d+'], methods: ['GET'])]
    public function download(Entite $entite, TrainingWorkflow $workflow, Inscription $inscription, int $version, WorkflowCertificateStorage $storage): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::SESSION_MANAGE, $entite);
        if ($workflow->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        $this->assertInscription($workflow, $inscription);
        $document = $storage->getVersion($inscription, $version);
        if (!$document) throw $this->createNotFoundException('Le certificat est indisponible.');
        $response = new BinaryFileResponse($document['path']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('Certificat-%d-v%d.pdf', $inscription->getId(), $version));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    private function assertInscription(TrainingWorkflow $workflow, ?Inscription $inscription): void
    {
        if (!$inscription || $inscription->getEntite()?->getId() !== $workflow->getEntite()?->getId() || $inscription->getSession()?->getId() !== $workflow->getSession()?->getId()) throw $this->createNotFoundException();
        foreach ($workflow->getConvention()->getInscriptions() as $candidate) if ($candidate->getId() === $inscription->getId()) return;
        throw $this->createNotFoundException();
    }

    private function back(Entite $entite, TrainingWorkflow $workflow): Response
    {
        return $this->redirectToRoute('app_administrateur_workflow_show', ['entite'=>$entite->getId(), 'id'=>$workflow->getId()]);
    }
}
