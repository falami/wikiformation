<?php
// src/Service/Bpf/BpfCalculator.php

declare(strict_types=1);

namespace App\Service\Bpf;

use App\Entity\Entite;
use App\Enum\ModeFinancement;
use App\Enum\StatusInscription;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final class BpfCalculator
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly Connection $db,
  ) {}

  /**
   * @return array<string,mixed>
   */
  public function computeForYear(Entite $entite, int $year): array
  {
    $start = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $year));
    $end   = new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $year));

    // 1) Heures par session (SQL natif => TIMESTAMPDIFF OK)
    $sql = <<<SQL
SELECT
  s.id AS session_id,
  f.id AS formation_id,
  f.titre AS formation_titre,
  SUM(TIMESTAMPDIFF(SECOND, sj.date_debut, sj.date_fin)) AS seconds_total
FROM session_jour sj
INNER JOIN session s ON s.id = sj.session_id
INNER JOIN formation f ON f.id = s.formation_id
WHERE s.entite_id = :entiteId
  AND sj.date_debut BETWEEN :start AND :end
GROUP BY s.id, f.id, f.titre
SQL;

    $rows = $this->db->fetchAllAssociative($sql, [
      'entiteId' => (int)$entite->getId(),
      'start'    => $start->format('Y-m-d H:i:s'),
      'end'      => $end->format('Y-m-d H:i:s'),
    ]);

    $sessionHours = []; // sessionId => hours
    $formations   = []; // formationId => aggregate
    $totalHours   = 0.0;

    foreach ($rows as $r) {
      $seconds = (int)($r['seconds_total'] ?? 0);
      if ($seconds <= 0) {
        continue;
      }

      $hours = round($seconds / 3600, 2);
      $sid   = (int)$r['session_id'];
      $fid   = (int)$r['formation_id'];

      $sessionHours[$sid] = ($sessionHours[$sid] ?? 0.0) + $hours;
      $totalHours += $hours;

      if (!isset($formations[$fid])) {
        $formations[$fid] = [
          'formationId' => $fid,
          'titre'       => (string)($r['formation_titre'] ?? ''),
          'sessions'    => 0,
          'heures'      => 0.0,
        ];
      }

      $formations[$fid]['sessions']++;
      $formations[$fid]['heures'] = round($formations[$fid]['heures'] + $hours, 2);
    }

    $sessionIds = array_keys($sessionHours);
    if (!$sessionIds) {
      return $this->emptyBpf($year, $start, $end);
    }

    // 2) Inscriptions éligibles
    $eligible = [
      StatusInscription::PREINSCRIT,
      StatusInscription::CONFIRME,
      StatusInscription::EN_COURS,
      StatusInscription::TERMINE,
    ];

    $inscRows = $this->em->createQueryBuilder()
      ->from('App\Entity\Inscription', 'i')
      ->join('i.session', 's')
      ->join('i.stagiaire', 'u')
      ->select('i.id as inscriptionId, s.id as sessionId, u.id as stagiaireId')
      ->addSelect('i.modeFinancement as modeFinancement')
      ->addSelect('i.status as status')
      ->where('s.entite = :entite')
      ->andWhere('s.id IN (:sessionIds)')
      ->setParameter('entite', $entite)
      ->setParameter('sessionIds', $sessionIds)
      ->getQuery()
      ->getArrayResult();

    $nbInscriptions   = 0;
    $stagiaires       = []; // set stagiaireId
    $heuresStagiaires = 0.0;

    $fin = $this->initFinancementBuckets();

    foreach ($inscRows as $r) {
      $status = $this->toStatusInscription($r['status'] ?? null);
      if (!$status || !\in_array($status, $eligible, true)) {
        continue;
      }

      $sid      = (int)$r['sessionId'];
      $hSession = (float)($sessionHours[$sid] ?? 0.0);

      $nbInscriptions++;
      $stagiaires[(int)$r['stagiaireId']] = true;
      $heuresStagiaires += $hSession;

      $mode = $this->toModeFinancement($r['modeFinancement'] ?? null) ?? ModeFinancement::INDIVIDUEL;
      $key  = $mode->value;

      if (!isset($fin[$key])) {
        $fin[$key] = ['label' => $key, 'stagiaires' => 0, 'heuresStagiaires' => 0.0];
      }

      $fin[$key]['stagiaires']++;
      $fin[$key]['heuresStagiaires'] = round($fin[$key]['heuresStagiaires'] + $hSession, 2);
    }

    $nbStagiaires     = \count($stagiaires);
    $heuresStagiaires = round($heuresStagiaires, 2);

    // 3) Produits (Factures) — conforme à TON entité Facture
    $fact = $this->em->createQueryBuilder()
      ->from('App\Entity\Facture', 'fa')
      ->select('COALESCE(SUM(fa.montantTtcCents),0) as ttc')
      ->addSelect('COALESCE(SUM(fa.montantHtCents),0) as ht')
      ->addSelect('COALESCE(SUM(fa.montantTvaCents),0) as tva')
      ->where('fa.entite = :entite')
      ->andWhere('fa.dateEmission BETWEEN :start AND :end')
      ->setParameter('entite', $entite)
      ->setParameter('start', $start)
      ->setParameter('end', $end)
      ->getQuery()
      ->getSingleResult();

    // 4) Encaissements (Paiements) — robuste avec TON modèle
    // On accepte 2 chemins possibles :
    //  - p.facture.entite
    //  - p.inscription.session.entite
    // + filtre sur datePaiement
    $pay = $this->em->createQueryBuilder()
      ->from('App\Entity\Paiement', 'p')
      ->join('p.facture', 'fa') // inner join car on filtre sur fa.entite
      ->select('COALESCE(SUM(p.montantCents),0) as enc')
      ->where('p.datePaiement BETWEEN :start AND :end')
      ->andWhere('fa.entite = :entite')
      ->setParameter('entite', $entite)
      ->setParameter('start', $start)
      ->setParameter('end', $end)
      ->getQuery()
      ->getSingleResult();


    // 5) Charges formateurs (optionnel)
    $chargesFormateurs = 0;
    try {
      $cf = $this->em->createQueryBuilder()
        ->from('App\Entity\ContratFormateur', 'c')
        ->select('COALESCE(SUM(c.montantPrevuCents + c.fraisMissionCents),0) as charges')
        ->where('c.entite = :entite')
        ->andWhere('c.dateCreation BETWEEN :start AND :end')
        ->setParameter('entite', $entite)
        ->setParameter('start', $start)
        ->setParameter('end', $end)
        ->getQuery()
        ->getSingleResult();

      $chargesFormateurs = (int)($cf['charges'] ?? 0);
    } catch (\Throwable) {
      $chargesFormateurs = 0;
    }

    // Tri formations par titre
    $formations = array_values($formations);
    usort($formations, static fn(array $a, array $b) => strcasecmp((string)$a['titre'], (string)$b['titre']));

    return [
      'year'  => $year,
      'start' => $start,
      'end'   => $end,

      'nbSessions'      => \count($sessionHours),
      'heuresFormation' => round($totalHours, 2),

      'nbInscriptions'   => $nbInscriptions,
      'nbStagiaires'     => $nbStagiaires,
      'heuresStagiaires' => $heuresStagiaires,

      'financement' => $fin,

      'produits' => [
        'ttcCents'           => (int)($fact['ttc'] ?? 0),
        'htCents'            => (int)($fact['ht'] ?? 0),
        'tvaCents'           => (int)($fact['tva'] ?? 0),
        'encaissementsCents' => (int)($pay['enc'] ?? 0),
      ],

      'charges' => [
        'formateursCents' => $chargesFormateurs,
      ],

      'formations' => $formations,
    ];
  }

  /**
   * @return array<string,array{label:string,stagiaires:int,heuresStagiaires:float}>
   */
  private function initFinancementBuckets(): array
  {
    $fin = [];
    foreach (ModeFinancement::cases() as $m) {
      $fin[$m->value] = [
        'label'           => $m->label(),
        'stagiaires'      => 0,
        'heuresStagiaires' => 0.0,
      ];
    }
    return $fin;
  }

  private function toModeFinancement(mixed $value): ?ModeFinancement
  {
    if ($value instanceof ModeFinancement) return $value;
    if ($value === null) return null;
    return ModeFinancement::tryFrom((string)$value);
  }

  private function toStatusInscription(mixed $value): ?StatusInscription
  {
    if ($value instanceof StatusInscription) return $value;
    if ($value === null) return null;
    return StatusInscription::tryFrom((string)$value);
  }

  private function emptyBpf(int $year, \DateTimeImmutable $start, \DateTimeImmutable $end): array
  {
    $fin = $this->initFinancementBuckets();

    return [
      'year'  => $year,
      'start' => $start,
      'end'   => $end,

      'nbSessions'      => 0,
      'heuresFormation' => 0.0,

      'nbInscriptions'   => 0,
      'nbStagiaires'     => 0,
      'heuresStagiaires' => 0.0,

      'financement' => $fin,

      'produits' => [
        'ttcCents'           => 0,
        'htCents'            => 0,
        'tvaCents'           => 0,
        'encaissementsCents' => 0,
      ],

      'charges' => [
        'formateursCents' => 0,
      ],

      'formations' => [],
    ];
  }
}
