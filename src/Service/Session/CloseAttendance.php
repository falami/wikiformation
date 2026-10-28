<?php

declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\{Attestation, Inscription, Session, Utilisateur};
use App\Enum\StatusInscription;
use App\Service\AssiduiteCalculator;
use App\Service\Document\RealisationCertificate;
use App\Service\Pdf\PdfManager;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class CloseAttendance
{
    public function __construct(private EntityManagerInterface $em, private AssiduiteCalculator $attendance,
        private RealisationCertificate $certificate, private PdfManager $pdf,
        #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    public function preview(Session $session): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Inscription::class)->findBy(['session'=>$session, 'entite'=>$session->getEntite()], ['id'=>'ASC']) as $registration) {
            $summary = $this->attendance->summary($registration);
            $eligible = in_array($registration->getStatus(), [StatusInscription::CONFIRME, StatusInscription::EN_COURS, StatusInscription::TERMINE, StatusInscription::ABSENT], true);
            $copy = clone $registration;
            $copy->setStatus(StatusInscription::TERMINE);
            $certificate = $this->certificate->data($copy);
            $outcome = !$eligible ? 'Exclu : inscription annulée ou non confirmée'
                : ($summary['rate'] === null ? 'En attente : présences à compléter ou créneaux à venir'
                : (!$certificate['ready'] ? 'En attente : durée suivie non justifiée'
                : ($registration->getAttestation() ? 'Clôture · attestation existante conservée' : 'Clôture · attestation à générer')));
            $rows[] = ['inscription'=>$registration, 'attendance'=>$summary, 'outcome'=>$outcome];
        }
        return $rows;
    }

    public function close(Session $session, Utilisateur $actor): array
    {
        $files = [];
        try {
            return $this->em->wrapInTransaction(function () use ($session, $actor, &$files): array {
                $this->em->refresh($session, LockMode::PESSIMISTIC_WRITE);
                if ($session->isSousTraitance()) throw new \DomainException('La génération automatique des attestations n’est pas disponible en sous-traitance.');
                $result = ['calculated'=>0, 'created'=>0, 'existing'=>0, 'pending'=>0, 'excluded'=>0];
                $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
                if ($session->getJours()->isEmpty()) throw new \DomainException('Ajoutez le planning avant de clôturer les émargements.');
                foreach ($session->getJours() as $day) {
                    if (!$day->getDateFin() || !$day->getDateDebut() || $day->getDateFin() <= $day->getDateDebut() || $day->getDateFin() > $now) {
                        throw new \DomainException('La clôture et les attestations sont possibles après la fin de tous les créneaux de formation.');
                    }
                }
                $registrations = $this->em->getRepository(Inscription::class)->findBy(['session'=>$session, 'entite'=>$session->getEntite()], ['id'=>'ASC']);
                foreach ($registrations as $registration) {
                    $this->em->refresh($registration, LockMode::PESSIMISTIC_WRITE);
                    if (!in_array($registration->getStatus(), [StatusInscription::CONFIRME, StatusInscription::EN_COURS, StatusInscription::TERMINE, StatusInscription::ABSENT], true)) { ++$result['excluded']; continue; }
                    $rate = $this->attendance->computeForInscription($registration);
                    if ($rate === null) { ++$result['pending']; continue; }
                    ++$result['calculated'];
                    $previous = $registration->getStatus();
                    $registration->setStatus(StatusInscription::TERMINE);
                    $data = $this->certificate->data($registration);
                    if (!$data['ready']) { $registration->setStatus($previous); ++$result['pending']; continue; }
                    $attestation = $registration->getAttestation();
                    if ($attestation) { ++$result['existing']; continue; }
                    $attestation = (new Attestation())->setInscription($registration)->setEntite($session->getEntite())->setCreateur($actor)
                        ->setDureeHeures($data['hours'])->setDateDelivrance($now)->setReussi($registration->isReussi());
                    $registration->setAttestation($attestation);
                    $this->em->persist($attestation);
                    $this->em->flush();
                    $path = $this->pdf->attestation([
                        'inscription'=>$registration, 'attestation'=>$attestation, 'stagiaire'=>$registration->getStagiaire(),
                        'session'=>$session, 'formation'=>$session->getFormation(), 'entite'=>$session->getEntite(),
                        'tauxAssiduite'=>$rate, 'numeroAttestation'=>$attestation->getNumero(),
                    ], 'attestation-'.bin2hex(random_bytes(16)).'.pdf');
                    $files[] = $path;
                    if (!is_file($path) || file_get_contents($path, false, null, 0, 4) !== '%PDF') throw new \RuntimeException('Le PDF de l’attestation n’a pas pu être créé.');
                    $public = $this->projectDir.'/public/';
                    if (!str_starts_with($path, $public)) throw new \RuntimeException('Emplacement des attestations invalide.');
                    $attestation->setPdfPath(substr($path, strlen($public)));
                    ++$result['created'];
                }
                $session->cloturerEmargements($actor->getUserIdentifier());
                return $result;
            });
        } catch (\Throwable $error) {
            foreach ($files as $file) if (is_file($file)) unlink($file);
            throw $error;
        }
    }
}
