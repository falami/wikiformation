<?php
// src/Service/Bpf/BpfCalculator.php

declare(strict_types=1);

namespace App\Service\Bpf;

use App\Entity\Entite;
use App\Entity\SessionJour;
use App\Enum\ModeFinancement;
use App\Enum\StatusInscription;
use Doctrine\ORM\EntityManagerInterface;

final class BpfCalculator
{
  public function __construct(
    private readonly EntityManagerInterface $em,
  ) {}

  /**
   * @return array<string,mixed>
   */
  public function computeForYear(Entite $entite, int $year): array
  {
    return $this->computeForPeriod($entite, new \DateTimeImmutable(sprintf('%d-01-01', $year)), new \DateTimeImmutable(sprintf('%d-12-31', $year)));
  }

  /** Données de rapprochement : jamais une déclaration réglementaire automatique. */
  public function computeForPeriod(Entite $entite, \DateTimeImmutable $start, \DateTimeImmutable $end): array
  {
    $start = $start->setTime(0, 0);
    $end = $end->setTime(23, 59, 59);
    $year = (int) $end->format('Y');
    $cutoff = min($end, new \DateTimeImmutable());
    // Même durée pédagogique que les documents, avec les pauses retirées.
    // On conserve le périmètre annuel par créneau, y compris pour une session
    // répartie sur deux années ; aucune formule SQL indépendante du planning.
    $jours = $this->em->createQueryBuilder()
      ->select('j', 's', 'f')->from(SessionJour::class, 'j')
      ->join('j.session', 's')->leftJoin('s.formation', 'f')
      ->where('s.entite = :entite')
      ->andWhere('j.dateFin <= :cutoff AND j.dateFin >= :start AND j.dateDebut <= :end')
      ->andWhere('s.status NOT IN (:excluded)')
      ->setParameter('excluded', [\App\Enum\StatusSession::DRAFT, \App\Enum\StatusSession::CANCELED])
      ->setParameter('cutoff', $cutoff)
      ->setParameter('entite', $entite)->setParameter('start', $start)->setParameter('end', $end)
      ->getQuery()->toIterable();
    $rows = [];
    foreach ($jours as $jour) {
      $session = $jour->getSession();
      $sid = (int) $session->getId();
      $rows[$sid] ??= ['session_id' => $sid, 'formation_id' => $session->getFormation()?->getId() ?? -$sid, 'code' => $session->getCode(), 'subcontract' => $session->isSousTraitance(),
        'formation_titre' => $session->getFormation()?->getTitre() ?? $session->getFormationIntituleLibre() ?? 'Formation sans intitulé', 'minutes_total' => 0];
      foreach ($jour->getDureeFormationMinutesParDate() as $date => $minutes) {
        if ($date >= $start->format('Y-m-d') && $date <= $cutoff->format('Y-m-d')) $rows[$sid]['minutes_total'] += $minutes;
      }
    }

    $sessionHours = []; // sessionId => hours
    $formations   = []; // formationId => aggregate
    $totalHours   = 0.0;

    foreach ($rows as $r) {
      $minutes = (int)($r['minutes_total'] ?? 0);
      if ($minutes <= 0) {
        continue;
      }

      $hours = round($minutes / 60, 2);
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
    // La facturation existe même sans session enregistrée dans la période.
    if (!$sessionIds) $sessionIds = [0];

    // 2) Inscriptions éligibles
    $eligible = [
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

    $split = ['direct' => ['count' => 0, 'hours' => 0.0], 'subcontract' => ['count' => 0, 'hours' => 0.0]];
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

      $bucket = $rows[$sid]['subcontract'] ? 'subcontract' : 'direct';
      ++$split[$bucket]['count'];
      $split[$bucket]['hours'] += $hSession;
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
      ->andWhere('fa.status != :canceled')->setParameter('canceled', \App\Enum\FactureStatus::CANCELED)
      ->andWhere('fa.devise = :currency')->setParameter('currency', 'EUR')
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


    $credits = $this->em->createQueryBuilder()->select('COALESCE(SUM(a.montantTtcCents), 0)')
      ->from('App\Entity\Avoir', 'a')->where('a.entite = :entite')->andWhere('a.dateEmission BETWEEN :start AND :end')
      ->setParameter('entite', $entite)->setParameter('start', $start)->setParameter('end', $end)->getQuery()->getSingleScalarResult();

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
        'formateursCents' => null,
      ],

      'formations' => $formations,
      'sessions' => array_values($rows),
      'split' => $split,
      'creditsTtcCents' => (int) $credits,
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

}
