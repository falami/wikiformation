<?php

declare(strict_types=1);
namespace App\Controller\Administrateur;

use App\Entity\{Entite, BpfDeclaration, AuditLog};
use App\Form\Administrateur\BpfDeclarationType;
use App\Service\Bpf\{BpfCalculator, BpfSchema, BpfPrefill};
use App\Service\Pdf\PdfManager;
use App\Security\Permission\TenantPermission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[Route('/administrateur/{entite}/bpf')]
#[IsGranted(TenantPermission::BPF_MANAGE, subject: 'entite')]
final class BpfController extends AbstractController
{
    public function __construct(private BpfCalculator $bpf, private PdfManager $pdfManager, private EntityManagerInterface $em, private BpfPrefill $prefill) {}

    private function period(Request $request): array
    {
        $year = $request->query->get('year', (string) ((int) date('Y') - 1));
        if (!preg_match('/^\d{4}$/', (string) $year) || (int) $year < 2000 || (int) $year > (int) date('Y') + 1) throw new BadRequestHttpException('Exercice invalide.');
        $dates = [];
        foreach (['start' => $year.'-01-01', 'end' => $year.'-12-31'] as $key => $default) {
            $raw = (string) $request->query->get($key, $default);
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
            if (!$date || $date->format('Y-m-d') !== $raw || (int) $date->format('Y') < 2000 || (int) $date->format('Y') > (int) date('Y') + 1) throw new BadRequestHttpException('Date d’exercice invalide.');
            $dates[] = $date;
        }
        if ($dates[1] < $dates[0] || $dates[0]->diff($dates[1])->days > 731) throw new BadRequestHttpException('La période doit être ordonnée et ne pas dépasser deux ans.');
        return $dates;
    }

    private function draft(Entite $entite, \DateTimeImmutable $start, \DateTimeImmutable $end): ?BpfDeclaration
    {
        return $this->em->getRepository(BpfDeclaration::class)->findOneBy(['entite' => $entite, 'startDate' => $start, 'endDate' => $end]);
    }

    private function snapshot(Entite $entite, BpfDeclaration $draft): AuditLog
    {
        $data = $draft->getData();
        return (new AuditLog())->setEntite($entite)->setCreateur($this->getUser())->setActor($this->getUser())
            ->setEvent('BPF_VERSION_'.$draft->getId())->setCreatedAt(new \DateTimeImmutable())->setPayload([
                'version' => $draft->getVersion(), 'data' => $data, 'entityName' => $entite->getNom(),
                'start' => $draft->getStartDate()->format('Y-m-d'), 'end' => $draft->getEndDate()->format('Y-m-d'),
                'updatedAt' => $draft->getUpdatedAt()->format(DATE_ATOM),
                'author' => $draft->getUpdatedBy() ? $draft->getUpdatedBy()->getPrenom().' '.$draft->getUpdatedBy()->getNom() : null,
                'sections' => BpfSchema::sections(), 'totals' => BpfSchema::totals($data),
                'issues' => array_merge(BpfSchema::checks($data, $draft->getEndDate()), $data['_generated']['issues'] ?? []),
            ]);
    }

    #[Route('', name: 'app_administrateur_bpf_index', methods: ['GET','POST'])]
    public function index(Entite $entite, Request $request): Response
    {
        [$start, $end] = $this->period($request);
        $draft = $this->draft($entite, $start, $end);
        $saved = $draft?->getData() ?? [];
        $history = $this->em->getRepository(BpfDeclaration::class)->findBy(['entite' => $entite], ['updatedAt' => 'DESC'], 20);
        $rules = [];
        foreach ($history as $item) foreach ($item->getData() as $key => $value) {
            if (str_starts_with($key, 'rule_') && $value !== null && !array_key_exists($key, $rules)) $rules[$key] = $value;
        }
        $rules = array_replace($rules, array_filter($saved, fn($k) => str_starts_with($k, 'rule_'), ARRAY_FILTER_USE_KEY));
        $preview = $this->prefill->generate($entite, $start, $end, $rules);
        $generation = $saved['_generated'] ?? null;
        $data = $draft ? $saved : $preview['data'];
        unset($data['_generated']);
        foreach ($preview['rules'] as $key => $spec) $data[$key] = $rules[$key] ?? $spec['default'];
        $form = $this->createForm(BpfDeclarationType::class, $data + ['_version' => (string) ($draft?->getVersion() ?? 0)], ['classifications' => $preview['rules']]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $posted = $form->getData();
            if ((string) $posted['_version'] !== (string) ($draft?->getVersion() ?? 0)) {
                $form->addError(new FormError('Une autre personne a modifié ce dossier. Rechargez la page avant d’enregistrer.'));
            } else {
                unset($posted['_version']);
                if ($request->request->get('bpf_action') === 'generate' || !$draft) {
                    $fresh = $this->prefill->generate($entite, $start, $end, $posted);
                    $posted = BpfPrefill::merge($posted, $fresh['data'], $generation['baseline'] ?? (!$draft ? $preview['data'] : []));
                    $generation = ['baseline' => $fresh['data'], 'issues' => $fresh['issues'], 'evidence' => $fresh['evidence'], 'incompleteSections' => $fresh['incompleteSections'], 'at' => $fresh['at']];
                    $generation['classifications'] = [];
                    foreach ($fresh['rules'] as $key => $spec) {
                        $value = $posted[$key] ?? null;
                        $generation['classifications'][] = ['label' => $spec['label'], 'value' => array_search($value, $spec['choices'], true) ?: 'Non renseigné'];
                    }
                }
                if ($generation) $posted['_generated'] = $generation;
                // Preserve a legacy preparation before its first change with version history enabled.
                $previousSnapshot = $draft && !$this->em->getRepository(AuditLog::class)->count(['entite' => $entite, 'event' => 'BPF_VERSION_'.$draft->getId()]) ? $this->snapshot($entite, $draft) : null;
                $draft ??= new BpfDeclaration($entite, $start, $end);
                $draft->save($posted, $this->getUser());
                try {
                    $this->em->wrapInTransaction(function () use ($draft, $entite, $previousSnapshot): void {
                        $this->em->persist($draft);
                        if ($previousSnapshot) $this->em->persist($previousSnapshot);
                        $this->em->flush();
                        $this->em->persist($this->snapshot($entite, $draft));
                    });
                    $this->addFlash('success', 'Votre préparation BPF a été enregistrée. Aucun dépôt administratif n’a été effectué.');
                    return $this->redirectToRoute('app_administrateur_bpf_index', ['entite' => $entite->getId(), 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]);
                } catch (OptimisticLockException|UniqueConstraintViolationException) {
                    throw new \Symfony\Component\HttpKernel\Exception\ConflictHttpException('Ce dossier a été enregistré simultanément. Rechargez la page avant de reprendre vos modifications.');
                }
            }
        }
        $checksData = $form->isSubmitted() && $form->isValid() ? $form->getData() : $data;
        $notices = BpfSchema::groupedNotices(array_merge(BpfSchema::checks($checksData, $end), $generation['issues'] ?? $preview['issues']));
        return $this->render('administrateur/bpf/index.html.twig', [
            'warningTargets' => array_combine($notices['warnings'], array_map(fn($issue) => BpfSchema::warningTarget($issue, $preview['rules']), $notices['warnings'])),
            'missingFields' => BpfSchema::missingFields($checksData), 'controlNotices' => $notices,
            'entite' => $entite, 'start' => $start, 'end' => $end, 'draft' => $draft, 'form' => $form->createView(),
            'sections' => BpfSchema::sections(), 'totals' => BpfSchema::totals($checksData), 'issues' => array_merge(BpfSchema::checks($checksData, $end), $generation['issues'] ?? $preview['issues']),
            'generation' => $generation ?? ['baseline' => $preview['data'], 'issues' => $preview['issues'], 'evidence' => $preview['evidence'], 'incompleteSections' => $preview['incompleteSections'], 'at' => $preview['at']], 'classifications' => $preview['rules'],
            'source' => $this->bpf->computeForPeriod($entite, $start, $end),
            'officialUrl' => BpfSchema::FORM_URL, 'noticeUrl' => BpfSchema::NOTICE_URL,
            'history' => $this->em->getRepository(BpfDeclaration::class)->findBy(['entite' => $entite], ['endDate' => 'DESC'], 20),
            'versions' => $draft ? $this->em->getRepository(AuditLog::class)->findBy(['entite' => $entite, 'event' => 'BPF_VERSION_'.$draft->getId()], ['id' => 'DESC'], 30) : [],
        ]);
    }

    #[Route('/pdf', name: 'app_administrateur_bpf_pdf', methods: ['GET'])]
    public function pdf(Entite $entite, Request $request): Response
    {
        [$start, $end] = $this->period($request);
        $draft = $this->draft($entite, $start, $end);
        if (!$draft) throw $this->createNotFoundException('Enregistrez votre préparation avant de l’exporter.');
        $data = $draft->getData();
        $context = [
            'entite' => $entite, 'start' => $start, 'end' => $end, 'draft' => $draft, 'data' => $data,
            'sections' => BpfSchema::sections(), 'totals' => BpfSchema::totals($data), 'issues' => array_merge(BpfSchema::checks($data, $end), $data['_generated']['issues'] ?? []),
        ];
        $version = $draft->getVersion();
        if ($request->query->has('revision')) {
            $raw = $request->query->get('revision');
            if (!is_string($raw) || !ctype_digit($raw)) throw new BadRequestHttpException('Version invalide.');
            $revision = $this->em->find(AuditLog::class, (int) $raw);
            if (!$revision || $revision->getEntite()?->getId() !== $entite->getId() || $revision->getEvent() !== 'BPF_VERSION_'.$draft->getId()) throw $this->createNotFoundException('Version indisponible.');
            $snapshot = $revision->getPayload();
            $version = $snapshot['version'];
            $context = [
                'entite' => ['id' => $entite->getId(), 'nom' => $snapshot['entityName']], 'start' => $snapshot['start'], 'end' => $snapshot['end'],
                'draft' => ['version' => $version, 'updatedAt' => $snapshot['updatedAt']], 'data' => $snapshot['data'],
                'sections' => $snapshot['sections'], 'totals' => $snapshot['totals'], 'issues' => $snapshot['issues'],
            ];
        }
        $response = $this->pdfManager->createPortrait($this->renderView('administrateur/bpf/pdf.html.twig', $context), 'Preparation-BPF-'.$entite->getId().'-'.$end->format('Y-m-d').'-v'.$version);
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }
}
