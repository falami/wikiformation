<?php

declare(strict_types=1);

namespace App\Controller\Administrateur;

use App\Entity\Entite;
use App\Repository\SessionRepository;
use App\Service\PasseportPrevention\{PasseportPreventionExporter, PasseportPreventionReadiness, PasseportPreventionCsvValidator};
use App\Service\UtilisateurEntite\UtilisateurEntiteManager;
use App\Security\Permission\TenantPermission;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, BinaryFileResponse, ResponseHeaderBag};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/administrateur/{entite}/passeport-prevention', name: 'app_administrateur_passeport_prevention_')]
#[IsGranted(TenantPermission::PASSEPORT_PREVENTION_MANAGE, subject: 'entite')]
final class PasseportPreventionController extends AbstractController
{
    public function __construct(
        private UtilisateurEntiteManager $utilisateurEntiteManager,
        private PasseportPreventionReadiness $readiness,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Entite $entite, SessionRepository $sessionRepo): Response
    {
        return $this->renderIndex($entite, $sessionRepo);
    }

    private function renderIndex(Entite $entite, SessionRepository $sessionRepo, ?array $validation = null, string $type = 'ADF', int $status = 200, array $formValues = []): Response
    {
        $sessions = $sessionRepo->createQueryBuilder('s')
            ->leftJoin('s.jours', 'j')->addSelect('j')
            ->leftJoin('s.inscriptions', 'i')->addSelect('i')
            ->leftJoin('i.stagiaire', 'u')->addSelect('u')
            ->leftJoin('s.formation', 'f')->addSelect('f')
            ->andWhere('s.entite = :e')->setParameter('e', $entite)
            ->orderBy('j.dateDebut', 'DESC')->getQuery()->getResult();
        $sessionRows = array_map(fn ($session) => $this->readiness->inspect($session, $entite), $sessions);
        $available = count(array_filter($sessionRows, static fn ($row) => $row['selectable']));
        $response = $this->render('administrateur/passeport/index.html.twig', [
            'entite' => $entite, 'sessionRows' => $sessionRows,
            'summary' => ['available' => $available, 'excluded' => count($sessions) - $available, 'total' => count($sessions)],
            'csvValidation' => $validation, 'validationType' => $type,
            'formValues' => $formValues,
            'utilisateurEntite' => $this->utilisateurEntiteManager->getRepository()->findOneBy(['entite' => $entite, 'utilisateur' => $this->getUser()]),
        ], new Response(status: $status));
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    #[Route('/export', name: 'export', methods: ['POST'])]
    public function export(Entite $entite, Request $request, SessionRepository $sessionRepo, PasseportPreventionExporter $exporter): Response
    {
        if (!$this->isCsrfTokenValid('passeport_export_'.$entite->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        try {
            $mode = $request->request->getString('mode');
            $type = $request->request->getString('declarationType');
            $category = $request->request->getInt('category');
            if (!in_array($mode, ['manual', 'period'], true) || !in_array($type, ['ADF', 'JDR'], true) || !in_array($category, [1, 2, 3, 4], true)) {
                throw new \InvalidArgumentException('Choisissez un mode, un type de justificatif et une catégorie de formation.');
            }
            foreach (['eligibilitySst', 'eligibilityEvidence', 'eligibilityTransferable', 'eligibilityAudience'] as $field) {
                if (!$request->request->getBoolean($field)) throw new \InvalidArgumentException('Confirmez les quatre critères du périmètre avant de préparer le fichier.');
            }
            if ($type === 'JDR' && $mode !== 'manual') {
                throw new \InvalidArgumentException('Pour un justificatif de réussite, sélectionnez les sessions manuellement : la date de début de validité sera à compléter.');
            }
            $from = null; $to = null;
            if ($mode === 'manual') {
                $ids = json_decode($request->request->getString('sessionIds', '[]'), true);
                if (!is_array($ids) || !array_is_list($ids) || !$ids || count($ids) > 500) throw new \InvalidArgumentException('Sélectionnez de 1 à 500 sessions pour ce fichier de travail.');
                foreach ($ids as $id) if (!is_int($id) || $id < 1) throw new \InvalidArgumentException('Sélection de sessions invalide.');
                $ids = array_values(array_unique($ids));
                $sessions = $sessionRepo->findByEntiteAndIds($entite, $ids);
                if (count($sessions) !== count($ids)) throw new \InvalidArgumentException('Certaines sessions sélectionnées ne sont pas accessibles dans cet organisme.');
            } else {
                $from = $this->date($request->request->getString('dateFrom'));
                $to = $this->date($request->request->getString('dateTo'))->setTime(23, 59, 59);
                if ($to < $from) throw new \InvalidArgumentException('La date de fin doit être postérieure ou égale à la date de début.');
                // The repository finds overlaps; filter the complete session end below (ADF reference date).
                $sessions = $sessionRepo->findByEntiteAndPeriodViaJours($entite, $from, $to);
            }
            $selected = [];
            foreach ($sessions as $session) {
                $review = $this->readiness->inspect($session, $entite);
                if (!$review['selectable']) {
                    if ($mode === 'manual') throw new \InvalidArgumentException('Une session sélectionnée n’est pas disponible pour cette préparation : '.$review['reason'].'.');
                    continue;
                }
                if ($from && ($review['end'] < $from || $review['end'] > $to)) continue;
                // JDR eligibility dates concern the actual validity date, not the session date.
                if ($type === 'ADF') {
                    $begin = new \DateTimeImmutable($category <= 2 ? '2025-09-01' : '2026-07-01', new \DateTimeZone('Europe/Paris'));
                    if ($review['end'] < $begin) {
                        if ($mode === 'manual') throw new \InvalidArgumentException('La date de fin d’une session précède le début de l’obligation pour la catégorie choisie.');
                        continue;
                    }
                }
                $selected[] = $session;
            }
            if (!$selected) throw new \InvalidArgumentException('Aucune session disponible pour ce périmètre. Vérifiez les dates, la catégorie et les inscriptions.');
            $file = $exporter->exportPreparationXlsx($entite, $selected, $type, $category);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());
            $values = [];
            foreach (['mode', 'declarationType', 'category', 'dateFrom', 'dateTo', 'sessionIds', 'eligibilitySst', 'eligibilityEvidence', 'eligibilityTransferable', 'eligibilityAudience'] as $field) {
                $value = $request->request->all()[$field] ?? '';
                $values[$field] = is_string($value) ? mb_substr($value, 0, $field === 'sessionIds' ? 10000 : 32) : '';
            }
            return $this->renderIndex($entite, $sessionRepo, status: 422, formValues: $values);
        }
        $response = new BinaryFileResponse($file);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'preparation-passeport-'.$type.'-'.date('Ymd').'.xlsx');
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->deleteFileAfterSend(true);
        return $response;
    }

    #[Route('/verifier', name: 'validate', methods: ['POST'])]
    public function validateCsv(Entite $entite, Request $request, SessionRepository $sessionRepo, PasseportPreventionCsvValidator $validator): Response
    {
        if (!$this->isCsrfTokenValid('passeport_validate_'.$entite->getId(), $request->request->getString('_token'))) throw $this->createAccessDeniedException();
        $file = $request->files->get('csvFile');
        $type = $request->request->getString('declarationType');
        $validation = ['errors' => [], 'rows' => 0];
        try {
            if (!in_array($type, ['ADF', 'JDR'], true)) throw new \InvalidArgumentException('Choisissez le type de déclaration à contrôler.');
            if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > 5 * 1024 * 1024 || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                throw new \InvalidArgumentException('Choisissez un fichier CSV de 5 Mo maximum pour ce contrôle local.');
            }
            $validation = $validator->validateCsv($file->getContent(), $type);
            $validation['errors'] = array_merge($validator->validateFilename($file->getClientOriginalName()), $validation['errors']);
        } catch (\InvalidArgumentException $e) {
            $validation['errors'][] = $e->getMessage();
        } finally {
            // Do not retain the uploaded NIR file in the application or in the rendered response.
            if ($file instanceof UploadedFile && is_file($file->getPathname())) unlink($file->getPathname());
        }
        return $this->renderIndex($entite, $sessionRepo, $validation, $type, $validation['errors'] ? 422 : 200);
    }

    private function date(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Renseignez une période valide.');
        return $date;
    }
}
