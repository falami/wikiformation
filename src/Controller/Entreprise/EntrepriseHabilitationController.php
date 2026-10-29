<?php

declare(strict_types=1);

namespace App\Controller\Entreprise;

use App\Entity\{Entite, HabilitationDossier, HabilitationRevision};
use App\Security\Permission\TenantPermission;
use App\Service\Entreprise\{EntrepriseHabilitations, EntreprisePortalContext};
use App\Service\Habilitation\HabilitationDocument;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/entreprise/{entite}/documents/habilitations', name: 'app_entreprise_documents_habilitation_', requirements: ['entite'=>'\d+'])]
#[IsGranted(TenantPermission::ENTREPRISE_DOCUMENTS_MANAGE, subject: 'entite')]
final class EntrepriseHabilitationController extends AbstractController
{
    public function __construct(private EntreprisePortalContext $portal, private EntrepriseHabilitations $documents) {}

    #[Route('/avis/ajax', name: 'avis_ajax', methods: ['POST'])]
    public function opinions(Entite $entite, Request $request): JsonResponse
    {
        $company = $this->portal->resolve($this->getUser(), $entite);
        $search = $request->request->all('search')['value'] ?? '';
        if (!is_string($search)) throw new BadRequestHttpException('La recherche doit être un texte.');
        $order = $request->request->all('order')[0] ?? [];
        if (!is_array($order)) throw new BadRequestHttpException('Ordre de tri invalide.');
        $column = $order['column'] ?? 3;
        if (!is_int($column) && (!is_string($column) || !preg_match('/^-?\d+$/D', $column))) throw new BadRequestHttpException('Colonne de tri invalide.');
        $direction = $order['dir'] ?? 'desc';
        if (!is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) throw new BadRequestHttpException('Sens de tri invalide.');
        $draw = max(0, $request->request->getInt('draw', 1));
        $page = $this->documents->opinionPage($entite, $company, $search, $request->request->getInt('start'), $request->request->getInt('length', 10), (int) $column, $direction);
        $values = static function (HabilitationRevision $r): array {
            $d = $r->getDossier(); $u = $d->getInscription()->getStagiaire(); $s = $d->getInscription()->getSession();
            return [trim($u->getPrenom().' '.$u->getNom()), $s->getFormationLabel(), $s->getCode() ?? ''];
        };
        $escape = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $data = [];
        foreach ($page['rows'] as $r) {
            $d = $r->getDossier(); [$person, $formation, $session] = $values($r);
            $params = ['entite'=>$entite->getId(), 'entreprise'=>$company->getId(), 'id'=>$d->getId()];
            $avisUrl = $this->generateUrl('app_entreprise_documents_habilitation_pdf', $params + ['kind'=>'avis']);
            $titleUrl = $this->generateUrl('app_habilitation_company_title', $params);
            $title = $page['titles'][$d->getId()] ?? null;
            $actions = '<div class="d-flex flex-wrap gap-2 justify-content-end"><a class="btn btn-sm btn-outline-secondary" href="'.$escape($avisUrl).'"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Avis PDF</a>';
            $actions .= '<a class="btn btn-sm btn-primary" href="'.$escape($titleUrl).'"><i class="bi bi-pen" aria-hidden="true"></i> '.($title ? 'Modifier le titre' : ($d->getEmployerData() ? 'Continuer le titre' : 'Préparer le titre')).'</a>';
            if ($title) $actions .= '<a class="btn btn-sm btn-outline-secondary" href="'.$escape($this->generateUrl('app_entreprise_documents_habilitation_pdf', $params + ['kind'=>'titre'])).'"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Titre PDF</a>';
            $data[] = ['stagiaire'=>$escape($person), 'formation'=>$escape($formation), 'session'=>$escape($session),
                'validation'=>$r->getCreatedAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i').'<br><small class="text-muted">'.$escape($r->getSnapshot()['signer'] ?? '').'</small>',
                'status'=>$title ? '<span class="badge bg-success-subtle text-success">Titre délivré</span>' : '<span class="badge bg-warning-subtle text-dark">'.($d->getEmployerData() ? 'Brouillon du titre' : 'Titre à établir').'</span>',
                'actions'=>$actions.'</div>'];
        }
        return new JsonResponse(['draw'=>$draw, 'recordsTotal'=>$page['total'], 'recordsFiltered'=>$page['filtered'], 'data'=>$data], 200, ['Cache-Control'=>'private, no-store']);
    }

    #[Route('/{id}/{kind}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id'=>'\d+', 'kind'=>'avis|titre'])]
    public function pdf(Entite $entite, #[MapEntity(id: 'id')] HabilitationDossier $dossier, string $kind, HabilitationDocument $renderer): Response
    {
        $company = $this->portal->resolve($this->getUser(), $entite);
        if (!$this->documents->available($dossier, $entite, $company) || !$this->documents->current($dossier, 'avis')) throw $this->createNotFoundException();
        $revision = $this->documents->current($dossier, $kind);
        if (!$revision) throw $this->createNotFoundException();
        return new Response($renderer->bytes($revision, $kind === 'avis'), 200, ['Content-Type'=>'application/pdf',
            'Content-Disposition'=>'attachment; filename="'.($kind === 'avis' ? 'Avis-post-formation-' : 'Titre-habilitation-').$dossier->getId().'.pdf"',
            'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
    }
}
