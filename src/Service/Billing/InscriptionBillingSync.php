<?php
// src/Service/Billing/InscriptionBillingSync.php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Facture;
use App\Entity\Inscription;
use App\Enum\FactureStatus;
use App\Repository\PaiementRepository;
use Doctrine\ORM\EntityManagerInterface;

final class InscriptionBillingSync
{
  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly PaiementRepository $paiementRepo,
  ) {}

  /**
   * @param Inscription[] $inscriptions
   */
  public function syncMany(array $inscriptions): void
  {
    // dédoublonnage + sécurité
    $map = [];
    foreach ($inscriptions as $i) {
      if (!$i instanceof Inscription) continue;
      if (!$i->getId()) continue;
      $map[$i->getId()] = $i;
    }
    if (!$map) return;

    foreach ($map as $inscription) {
      $this->syncOne($inscription);
    }

    $this->em->flush();
  }

  public function syncOne(Inscription $inscription): void
  {
    $dueTotal  = 0;
    $paidTotal = 0;

    /** @var Facture $facture */
    foreach ($inscription->getFactures() as $facture) {
      // ignore les annulées
      if (($facture->getStatus()?->value ?? null) === FactureStatus::CANCELED->value) {
        continue;
      }

      $ttcFacture = $this->factureTtcTotalCents($facture);
      if ($ttcFacture <= 0) continue;

      // base due de CETTE inscription sur CETTE facture
      $base = $this->facturePartForInscriptionCents($facture, $inscription, $ttcFacture);
      if ($base <= 0) continue;

      $dueTotal += $base;

      // paid facture (en base)
      $paidFacture = (int) $this->paiementRepo->sumPaidForFacture($facture->getId());
      if ($paidFacture <= 0) continue;

      // allocation paid au prorata de la base de l’inscription
      $paidPart = (int) round($paidFacture * ($base / $ttcFacture));
      $paidTotal += max(0, min($paidPart, $base)); // on borne
    }

    $inscription->setMontantDuCents($dueTotal > 0 ? $dueTotal : null);
    $inscription->setMontantRegleCents($paidTotal > 0 ? $paidTotal : null);
  }

  /**
   * TTC total = montantTtcCents (hors débours) + débours TTC (si champ dispo)
   */
  private function factureTtcTotalCents(Facture $f): int
  {
    $ttcHd = (int) ($f->getTtcTotalCents() ?? 0);

    // si tu as un champ dédié plus tard, il sera automatiquement pris
    $debours = 0;
    if (method_exists($f, 'getMontantDeboursTtcCents')) {
      $debours = (int) ($f->getMontantDeboursTtcCents() ?? 0);
    }

    return max(0, $ttcHd + $debours);
  }

  /**
   * Si facture liée à plusieurs inscriptions :
   * - par défaut : split égal (simple & stable)
   * - si tu veux une règle plus “métier” plus tard (ex: basé sur coût de session),
   *   tu modifies UNIQUEMENT ici.
   */
  private function facturePartForInscriptionCents(Facture $f, Inscription $target, int $ttcFacture): int
  {
    $inscs = $f->getInscriptions();
    $n = $inscs->count();
    if ($n <= 1) {
      return $ttcFacture;
    }

    // split égal + gestion des restes (le dernier prend le reste)
    $each = intdiv($ttcFacture, $n);
    $rest = $ttcFacture - ($each * $n);

    // on détermine un ordre stable (par id)
    $ids = [];
    foreach ($inscs as $i) $ids[] = $i->getId() ?? 0;
    sort($ids);

    $isLast = ($target->getId() ?? 0) === (end($ids) ?: 0);
    return $each + ($isLast ? $rest : 0);
  }
}
