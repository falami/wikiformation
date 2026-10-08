<?php

declare(strict_types=1);

namespace App\Controller\Opco;

use App\Entity\{Entite, Utilisateur, UtilisateurEntite};
use App\Service\Billing\EntitlementService;
use App\Service\Delegation\{DossierAccess, DossierRegistry, DossierDocumentResponse};
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

/** Read-only partner space. Commercial write actions are deliberately not routed here. */
#[Route('/opco/{entite}', name: 'app_opco_', defaults: ['espace' => 'opco'], requirements: ['entite' => '\d+'])]
final class DossierController extends AbstractController
{
    public function __construct(
        private readonly DossierAccess $access,
        private readonly DossierRegistry $registry,
        private readonly EntitlementService $entitlements,
    ) {}

    private function context(Entite $entite): UtilisateurEntite
    {
        $user = $this->getUser();
        if (!$user instanceof Utilisateur) {
            throw $this->createAccessDeniedException();
        }
        $membership = $this->access->membership($user, $entite, UtilisateurEntite::TENANT_OPCO);
        if (!$this->entitlements->isEntiteActive($entite)) {
            throw $this->createAccessDeniedException('L’abonnement de cet organisme doit être renouvelé par son administrateur.');
        }
        return $membership;
    }

    private function config(string $module): array
    {
        if (!in_array($module, DossierRegistry::PARTNER_MODULES, true)) {
            throw $this->createNotFoundException();
        }
        return $this->registry->config($module);
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(#[MapEntity(id: 'entite')] Entite $entite): Response
    {
        $membership = $this->context($entite);
        $modules = array_intersect_key(DossierRegistry::MODULES, array_flip(DossierRegistry::PARTNER_MODULES));
        $counts = [];
        foreach ($modules as $key => $config) {
            $counts[$key] = count($this->access->rows($membership, $key));
        }
        return $this->render('opco/dashboard.html.twig', ['entite' => $entite, 'espace' => 'opco', 'modules' => $modules, 'counts' => $counts]);
    }

    #[Route('/dossiers/{module}', name: 'list', methods: ['GET'])]
    public function list(#[MapEntity(id: 'entite')] Entite $entite, string $module, Request $request): Response
    {
        $membership = $this->context($entite);
        $config = $this->config($module);
        $query = mb_substr(trim($request->query->getString('q')), 0, 150);
        $rows = [];
        foreach ($this->access->rows($membership, $module) as $record) {
            $title = $this->registry->title($record);
            $details = $this->registry->details($module, $record);
            if ($query !== '' && !str_contains(mb_strtolower($title . ' ' . implode(' ', $details)), mb_strtolower($query))) {
                continue;
            }
            $rows[] = ['id' => $record->getId(), 'title' => $title, 'status' => $details['Statut'] ?? 'Attribué'];
        }
        $total = count($rows);
        $page = max(1, $request->query->getInt('page', 1));
        $rows = array_slice($rows, ($page - 1) * 25, 25);
        return $this->render('opco/list.html.twig', compact('entite', 'module', 'config', 'query', 'rows', 'total', 'page') + [
            'espace' => 'opco', 'canCreate' => false, 'companies' => [], 'companyId' => 0,
        ]);
    }

    #[Route('/dossiers/{module}/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(#[MapEntity(id: 'entite')] Entite $entite, string $module, int $id): Response
    {
        $membership = $this->context($entite);
        $config = $this->config($module);
        $this->access->grant($membership, $module, $id);
        $record = $this->access->record($entite, $module, $id);
        return $this->render('opco/show.html.twig', [
            'entite' => $entite, 'espace' => 'opco', 'module' => $module, 'id' => $id, 'config' => $config,
            'title' => $this->registry->title($record), 'details' => $this->registry->details($module, $record),
        ]);
    }

    #[Route('/dossiers/{module}/{id}/pdf', name: 'pdf', requirements: ['id' => '\d+', 'module' => 'factures|conventions'], methods: ['GET'])]
    public function pdf(#[MapEntity(id: 'entite')] Entite $entite, string $module, int $id, DossierDocumentResponse $documents): Response
    {
        $membership = $this->context($entite);
        $this->config($module);
        $this->access->grant($membership, $module, $id);
        return $documents->response($entite, $module, $this->access->record($entite, $module, $id));
    }
}
