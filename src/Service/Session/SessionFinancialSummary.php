<?php

declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\{Session, ConventionContrat, ContratFormateur};
use App\Enum\ContratFormateurStatus;

final class SessionFinancialSummary
{
    /** @param iterable<ConventionContrat> $conventions @param iterable<ContratFormateur> $contracts */
    public function calculate(Session $session, iterable $conventions, iterable $contracts): array
    {
        $amounts = []; $quotes = []; $count = 0; $estimated = false;
        foreach ($conventions as $convention) {
            if ($convention->getEntite() !== $session->getEntite() || $convention->getSession() !== $session) continue;
            ++$count;
            $quote = $convention->getDevis();
            if ($quote && $quote->getEntite() !== $session->getEntite()) continue;
            $currency = $quote?->getDevise() ?? 'EUR';
            if ($convention->getMontantHtCents() !== null) {
                $ht = $convention->getMontantHtCents();
                $tax = $convention->getMontantTvaCents();
            } elseif ($quote) {
                $key = $quote->getId() ?? spl_object_id($quote);
                if (isset($quotes[$key])) continue;
                $quotes[$key] = true;
                $ht = $quote->getMontantHtCents();
                $tax = $quote->getMontantTvaCents();
            } else {
                $ht = $session->getTarifEffectifCents() * $convention->getEffectifTotal();
                $tax = (int) round($ht * $convention->getTauxTva() / 100);
                $estimated = true;
            }
            $amounts[$currency]['ht'] = ($amounts[$currency]['ht'] ?? 0) + $ht;
            $amounts[$currency]['tax'] = ($amounts[$currency]['tax'] ?? 0) + $tax;
        }
        if (!$count) {
            $ht = $session->getTarifEffectifCents() * (new SessionParticipantCount())->count($session);
            $amounts['EUR'] = ['ht' => $ht, 'tax' => (int) round($ht * ($session->getFormation()?->getTauxTva() ?? 0) / 100)];
            $estimated = true;
        }
        $cost = 0; $trainerCount = 0; $covered = [];
        foreach ($contracts as $contract) {
            if ($contract->getEntite() !== $session->getEntite() || $contract->getSession() !== $session
                || $contract->getStatus() === ContratFormateurStatus::RESILIE) continue;
            $cost += $contract->getMontantPrevuCents() + $contract->getFraisMissionCents();
            ++$trainerCount;
            if ($contract->getFormateur()) $covered[spl_object_id($contract->getFormateur())] = true;
        }
        $missing = 0;
        foreach ($session->getFormateursEffectifs() as $trainer) if (!isset($covered[spl_object_id($trainer)])) ++$missing;
        ksort($amounts);
        return ['amounts' => $amounts, 'estimated' => $estimated, 'conventions' => $count, 'trainerCost' => $cost,
            'contracts' => $trainerCount, 'missingTrainers' => $missing,
            'remaining' => count($amounts) === 1 && isset($amounts['EUR']) && $trainerCount > 0 && $missing === 0 ? $amounts['EUR']['ht'] - $cost : null];
    }
}
