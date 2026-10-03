<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Automation\{TrainingWorkflow, WorkflowParticipant};
use App\Entity\{Entite, Utilisateur};
use App\Security\Permission\TenantPermission;
use App\Service\Automation\{WorkflowParticipantImporter, WorkflowPortal, WorkflowSignature};
use App\Service\Convention\ConventionDocument;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request, Response, ResponseHeaderBag};
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException, ConflictHttpException};
use Symfony\Component\Routing\Attribute\Route;

final class WorkflowPortalController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly WorkflowPortal $portal, private readonly WorkflowSignature $signature) {}

    #[Route('/dossier-formation/{id}/{token}', name: 'app_workflow_portal', requirements: ['id' => '\d+', 'token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function show(TrainingWorkflow $workflow, string $token, Request $request): Response
    {
        $this->assertLink($workflow, $token);
        $error = null; $status = 200;
        $rows = $this->rows($workflow);
        $values = array_map(static fn(WorkflowParticipant $p): array => ['prenom' => $p->getPrenom(), 'nom' => $p->getNom(), 'email' => $p->getEmail(), 'dateNaissance' => $p->getDateNaissance()?->format('Y-m-d'), 'imported' => $p->getInscription() !== null], $rows);
        $action = $request->request->getString('action');
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('workflow_portal_'.$workflow->getId(), $request->request->getString('_token'))) throw new BadRequestHttpException('Le formulaire a expiré. Rechargez la page.');
            if ($action === 'participants') {
                $values = $request->request->all('participants');
                try {
                    $clean = $this->validateParticipants($values, $workflow, $rows);
                    $this->em->wrapInTransaction(function () use ($workflow, $token, $request, $clean): void {
                        $this->lock($workflow, $token, $request);
                        $existing = $this->rows($workflow);
                        foreach ($clean as $position => $data) {
                            $row = $existing[$position] ?? (new WorkflowParticipant())->setWorkflow($workflow)->setPosition($position);
                            if ($row->getInscription()) continue;
                            $row->setPrenom($data['prenom'])->setNom($data['nom'])->setEmail($data['email'])->setDateNaissance($data['dateNaissance']); $row->touch();
                            $this->em->persist($row);
                        }
                        foreach ($existing as $position => $row) if (!isset($clean[$position]) && !$row->getInscription()) $this->em->remove($row);
                        $options = $workflow->getOptions();
                        $convention = $workflow->getConvention();
                        if (!$convention->isSigned()) {
                            $oldNames = $options['portalFreeNames'] ?? [];
                            $kept = array_values(array_filter($convention->getParticipantsLibresListe(), static fn(string $name): bool => !in_array($name, $oldNames, true)));
                            $linkedNames = [];
                            foreach ($convention->getInscriptions() as $inscription) $linkedNames[] = trim($inscription->getStagiaire()?->getPrenom().' '.$inscription->getStagiaire()?->getNom());
                            $newNames = [];
                            foreach ($clean as $position => $data) if (!(($existing[$position] ?? null)?->getInscription())) $newNames[] = trim($data['prenom'].' '.$data['nom']);
                            $newNames = array_values(array_diff($newNames, $linkedNames));
                            $convention->setParticipantsLibres(implode("\n", array_values(array_unique(array_merge($kept, $newNames)))))->setPdfPath(null);
                            $options['portalFreeNames'] = $newNames;
                        }
                        $options['portalRevision'] = (int) ($options['portalRevision'] ?? 0) + 1;
                        $workflow->setOptions($options);
                    });
                    $this->addFlash('success', 'Les participants ont été transmis à l’organisme. Vous pourrez compléter les informations manquantes.');
                    return $this->redirectToRoute('app_workflow_portal', ['id' => $workflow->getId(), 'token' => $token]);
                } catch (\DomainException $e) {
                    $error = $e->getMessage(); $status = 422;
                    $values = array_slice(array_filter($values, 'is_array'), 0, 200, true);
                    foreach ($values as &$value) foreach (['prenom', 'nom', 'email', 'dateNaissance'] as $field) $value[$field] = is_string($value[$field] ?? null) ? mb_substr($value[$field], 0, 180) : '';
                    unset($value);
                }
            } elseif ($action === 'sign') {
                $name = trim($request->request->getString('signerName')); $role = trim($request->request->getString('signerRole'));
                if ($name === '' || mb_strlen($name) > 100 || $role === '' || mb_strlen($role) > 100 || $request->request->getString('consent') !== '1') {
                    $error = 'Indiquez votre nom, votre fonction et confirmez votre accord avant de signer.'; $status = 422;
                } else {
                    $written = null;
                    try {
                        $this->em->wrapInTransaction(function () use ($workflow, $token, $request, $name, $role, &$written): void {
                            $this->lock($workflow, $token, $request);
                            if (!$this->portal->canSign($workflow)) throw new \DomainException('La signature est indisponible : la convention est déjà signée ou la formation a commencé.');
                            $written = $this->signature->sign($workflow, $name, $role, $request->request->getString('documentDigest'), $request->getClientIp(), $request->headers->get('User-Agent'));
                            $options = $workflow->getOptions(); $options['portalRevision'] = (int) ($options['portalRevision'] ?? 0) + 1; $workflow->setOptions($options);
                        });
                    } catch (\Throwable $e) {
                        if ($written && is_file($written)) unlink($written);
                        // A transaction failure closes Doctrine; return an explicit reload page instead of rendering managed data.
                        if ($e instanceof \DomainException) return $this->harden(new Response('<!doctype html><html lang="fr"><meta charset="utf-8"><title>Convention à actualiser</title><p>'.htmlspecialchars($e->getMessage(), ENT_QUOTES).'</p><p><a href="'.htmlspecialchars($this->portal->url($workflow), ENT_QUOTES).'">Recharger le dossier</a></p></html>', 409));
                        throw $e;
                    }
                    $this->addFlash('success', 'Votre convention est signée. La copie signée et la trace de votre consentement ont été conservées.');
                    return $this->redirectToRoute('app_workflow_portal', ['id' => $workflow->getId(), 'token' => $token]);
                }
            } else throw $this->createNotFoundException();
        }
        return $this->harden($this->render('workflow/portal.html.twig', [
            'workflow' => $workflow, 'token' => $token, 'participants' => $values, 'error' => $error,
            'canSign' => $this->portal->canSign($workflow), 'digest' => $workflow->getConvention()->isSigned() ? '' : $this->signature->digest($workflow),
            'expiresAt' => $this->portal->expiresAt($workflow), 'maxParticipants' => $this->maximum($workflow, $rows),
            'revision' => (int) ($workflow->getOptions()['portalRevision'] ?? 0),
        ], new Response(status: $status)));
    }

    #[Route('/dossier-formation/{id}/{token}/convention', name: 'app_workflow_portal_convention', requirements: ['id' => '\d+', 'token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function convention(TrainingWorkflow $workflow, string $token, ConventionDocument $document): Response
    {
        $this->assertLink($workflow, $token);
        $response = $workflow->getConvention()->isSigned() ? $document->response($workflow->getConvention()) : new BinaryFileResponse($this->signature->previewPath($workflow));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'Convention.pdf'));
        return $this->harden($response);
    }

    #[Route('/administrateur/{entite}/parcours/{id}/importer-participants', name: 'app_administrateur_workflow_import_participants', requirements: ['entite' => '\d+', 'id' => '\d+'], methods: ['POST'])]
    public function import(Entite $entite, TrainingWorkflow $workflow, Request $request, WorkflowParticipantImporter $importer): Response
    {
        $this->denyAccessUnlessGranted(TenantPermission::SESSION_MANAGE, $entite);
        if ($workflow->getEntite()?->getId() !== $entite->getId()) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('workflow_import_'.$workflow->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $actor = $this->getUser(); if (!$actor instanceof Utilisateur) throw $this->createAccessDeniedException();
        try {
            $result = $importer->import($workflow, $actor);
            $this->addFlash('success', sprintf('%d participant(s) importé(s). %d à compléter ou à rapprocher manuellement (email ou compte).', $result['imported'], $result['pending']));
        } catch (\DomainException|\App\Exception\BillingQuotaExceededException $e) { $this->addFlash('warning', $e->getMessage()); }
        return $this->redirectToRoute('app_administrateur_workflow_show', ['entite' => $entite->getId(), 'id' => $workflow->getId()]);
    }

    #[Route('/dossier-formation/{id}/{token}/preferences', name: 'app_workflow_portal_preferences', requirements: ['id' => '\d+', 'token' => '[a-f0-9]{64}'], methods: ['GET', 'POST'])]
    public function preferences(TrainingWorkflow $workflow, string $token, Request $request): Response
    {
        if (!$this->portal->isRenewalTokenValid($workflow, $token)) throw $this->createNotFoundException();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('workflow_preferences_'.$workflow->getId(), $request->request->getString('_token'))) throw new BadRequestHttpException('Le formulaire a expiré. Rechargez la page.');
            $this->em->wrapInTransaction(function () use ($workflow, $token): void {
                $this->em->refresh($workflow, LockMode::PESSIMISTIC_WRITE);
                if (!$this->portal->isRenewalTokenValid($workflow, $token)) throw $this->createNotFoundException();
                $workflow->setRenewalOptOut(true);
            });
        }
        return $this->harden($this->render('workflow/preferences.html.twig', ['workflow' => $workflow]));
    }

    private function assertLink(TrainingWorkflow $workflow, string $token): void
    {
        if (!$this->portal->isValid($workflow, $token)) throw $this->createNotFoundException('Ce lien n’est plus disponible. Contactez votre organisme de formation.');
    }

    private function lock(TrainingWorkflow $workflow, string $token, Request $request): void
    {
        $this->em->refresh($workflow, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($workflow->getConvention(), LockMode::PESSIMISTIC_WRITE);
        $this->assertLink($workflow, $token);
        if ((int) ($workflow->getOptions()['portalRevision'] ?? 0) !== $request->request->getInt('revision')) throw new ConflictHttpException('Le dossier a été actualisé. Rechargez la page avant de réessayer.');
    }

    /** @return array<int, WorkflowParticipant> */
    private function rows(TrainingWorkflow $workflow): array
    {
        $result = [];
        foreach ($this->em->getRepository(WorkflowParticipant::class)->findBy(['workflow' => $workflow], ['position' => 'ASC']) as $row) $result[$row->getPosition()] = $row;
        return $result;
    }

    private function maximum(TrainingWorkflow $workflow, array $rows): int
    {
        $imported = count(array_filter($rows, static fn(WorkflowParticipant $p): bool => $p->getInscription() !== null));
        return max(0, min(200, $workflow->getConvention()->getEffectifTotal() - $workflow->getConvention()->getInscriptions()->count() + $imported));
    }

    private function validateParticipants(array $values, TrainingWorkflow $workflow, array $rows): array
    {
        if (count($values) > $this->maximum($workflow, $rows)) throw new \DomainException('Le nombre de participants dépasse l’effectif prévu. Contactez l’organisme pour le modifier.');
        $clean = []; $emails = [];
        foreach ($rows as $row) if ($row->getInscription() && $row->getEmail()) $emails[$row->getEmail()] = true;
        foreach ($values as $position => $data) {
            if (!ctype_digit((string) $position) || (int) $position > 199 || !is_array($data)) throw new \DomainException('Liste de participants invalide.');
            foreach (['prenom', 'nom', 'email', 'dateNaissance'] as $field) if (isset($data[$field]) && !is_string($data[$field])) throw new \DomainException('Informations invalides.');
            if (($rows[$position] ?? null)?->getInscription()) {
                $p = $rows[$position]; $clean[(int) $position] = ['prenom'=>$p->getPrenom(), 'nom'=>$p->getNom(), 'email'=>$p->getEmail(), 'dateNaissance'=>$p->getDateNaissance()]; continue;
            }
            $first = trim($data['prenom'] ?? ''); $last = trim($data['nom'] ?? ''); $email = mb_strtolower(trim($data['email'] ?? '')); $birth = trim($data['dateNaissance'] ?? '');
            if ($first === '' && $last === '' && $email === '' && $birth === '') continue;
            if ($first === '' || $last === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) throw new \DomainException('Le prénom et le nom sont obligatoires (100 caractères maximum).');
            if ($email !== '' && (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($emails[$email]))) throw new \DomainException('Vérifiez les adresses email : elles doivent être valides et différentes pour chaque participant.');
            if ($email !== '') $emails[$email] = true;
            $date = $birth === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $birth);
            if ($birth !== '' && (!$date || $date->format('Y-m-d') !== $birth || $date > new \DateTimeImmutable('today') || $date < new \DateTimeImmutable('today -120 years'))) throw new \DomainException('Vérifiez la date de naissance.');
            $clean[(int) $position] = ['prenom' => $first, 'nom' => $last, 'email' => $email ?: null, 'dateNaissance' => $date];
        }
        foreach ($rows as $position => $row) if ($row->getInscription() && !isset($clean[$position])) throw new \DomainException('Un participant déjà validé ne peut pas être retiré. Contactez l’organisme.');
        return $clean;
    }

    private function harden(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        return $response;
    }
}
