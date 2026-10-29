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
                $ht = $session->estimatePriceForParticipants($convention->getEffectifTotal());
                $tax = (int) round($ht * $convention->getTauxTva() / 100);
                $estimated = true;
            }
            $amounts[$currency]['ht'] = ($amounts[$currency]['ht'] ?? 0) + $ht;
            $amounts[$currency]['tax'] = ($amounts[$currency]['tax'] ?? 0) + $tax;
        }
        if (!$count) {
            $ht = $session->getTarifTotalHtCents();
            $amounts['EUR'] = ['ht' => $ht, 'tax' => (int) round($ht * $session->getTauxTvaEffectif() / 100)];
            $estimated = true;
        }
        $responsibleOnly = $session->isFormationAssureeParResponsable();
        $cost = 0; $trainerTax = 0; $trainerCount = 0; $covered = [];
        foreach ($contracts as $contract) {
            if ($responsibleOnly) continue;
            if ($contract->getEntite() !== $session->getEntite() || $contract->getSession() !== $session
                || $contract->getStatus() === ContratFormateurStatus::RESILIE) continue;
            $cost += $contract->getTotalHtCents();
            $trainerTax += $contract->getMontantTvaCents();
            ++$trainerCount;
            if ($contract->getFormateur()) $covered[spl_object_id($contract->getFormateur())] = true;
            foreach ($contract->getIntervenantsCouverts() as $actualTrainer) {
                if ($actualTrainer->getEntite() === $session->getEntite()) $covered[spl_object_id($actualTrainer)] = true;
            }
        }
        $missing = 0;
        foreach ($session->getFormateursEffectifs() as $trainer) if (!$responsibleOnly && (!isset($covered[spl_object_id($trainer)]))) ++$missing;
        ksort($amounts);
        $base = $session->getMontantGlobalCents() ?? $session->getTarifEffectifCents();
        $tax = (int) round($base * $session->getTauxTvaEffectif() / 100);
        return ['global' => $session->getMontantGlobalCents() !== null, 'sessionHt' => $base, 'sessionRate' => $session->getTauxTvaEffectif(), 'sessionTax' => $tax, 'sessionTtc' => $base + $tax, 'amounts' => $amounts, 'estimated' => $estimated, 'conventions' => $count, 'trainerCost' => $cost, 'trainerTax' => $trainerTax, 'trainerTtc' => $cost + $trainerTax,
            'responsibleOnly' => $responsibleOnly, 'contracts' => $trainerCount, 'missingTrainers' => $missing,
            'remaining' => count($amounts) === 1 && isset($amounts['EUR']) && $trainerCount > 0 && $missing === 0 ? $amounts['EUR']['ht'] - $cost : null];
    }
}
