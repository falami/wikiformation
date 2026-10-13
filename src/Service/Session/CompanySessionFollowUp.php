<?php
declare(strict_types=1);
namespace App\Service\Session;

use App\Entity\{Session, Entreprise};
use App\Enum\{StatusSession, StatusInscription};

/** Completion counts restricted to the selected company’s learners. */
final class CompanySessionFollowUp
{
    public function summarize(Session $session, Entreprise $company, \DateTimeImmutable $today, iterable $conventions = []): ?array
    {
        if ($session->getEntite() !== $company->getEntite() || $session->isSousTraitance()
            || $session->getStatus() === StatusSession::CANCELED) return null;
        $periods = []; $last = null;
        foreach ($session->getJours() as $jour) {
            $start = $jour->getDateDebut(); $end = $jour->getDateFin();
            if (!$start || !$end || $end <= $start) continue;
            $last = max($last ?? $end->format('Y-m-d'), $end->format('Y-m-d'));
            $day = $start->format('Y-m-d');
            if ($start->format('H:i') < '13:00') $periods[$day]['AM'] = true;
            if ($end->format('H:i') > '13:00') $periods[$day]['PM'] = true;
        }
        if (!$last || $last >= $today->format('Y-m-d')) return null;
        $people = [];
        foreach ($session->getInscriptions() as $i) {
            $u = $i->getStagiaire();
            if ($u && $i->getEntite() === $session->getEntite() && $i->getEntreprise() === $company && !in_array($i->getStatus(), [StatusInscription::ANNULE, StatusInscription::ABSENT], true)
                && !$session->hasFormateurUtilisateur($u)) $people['user:'.$u->getId()] = true;
        }
        // Match QR guest keys without creating accounts or writing during dashboard reads.
        foreach ($conventions as $c) {
            if ($c->getEntreprise() !== $company || $c->getEntite() !== $session->getEntite() || $c->getSession() !== $session) continue;
            $occurrences = [];
            foreach ($c->getParticipantsLibresListe() as $name) {
                $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)), 'UTF-8');
                $n = $occurrences[$normalized] = ($occurrences[$normalized] ?? 0) + 1;
                $people['guest:'.$c->getId().':'.hash('sha256', $normalized.':'.$n)] = true;
            }
        }
        $expected = [];
        if ($session->isEmargementEnAttenteRequis()) foreach ($periods as $day => $halves) foreach ($halves as $half => $_) {
            foreach ($people as $key => $_person) $expected[$day.':'.$half.':'.$key] = true;

        }
        foreach ($session->getEmargements() as $e) {
            if (!$e->getSignedAt() && !$e->getSignaturePath() && !$e->getSignatureDataUrl()) continue;
            // Trainer signatures use "trainer"; keep compatibility with "formateur".
            $key = $e->getUtilisateur() ? (in_array($e->getRole(), ['trainer', 'formateur'], true) ? 'trainer:' : 'user:').$e->getUtilisateur()->getId() : $e->getParticipantAccess()?->getSourceKey();
            unset($expected[$e->getDateJour()->format('Y-m-d').':'.$e->getPeriode()->value.':'.$key]);
        }
        foreach ($session->getInscriptions() as $inscription) {
            if ($inscription->getEntite() !== $session->getEntite() || $inscription->getEntreprise() !== $company) continue;
            foreach ($inscription->getPresencesManuelles() as $key => $record) {
                if (in_array($record['status'] ?? '', ['paper', 'absent'], true)) {
                    unset($expected[$key.':user:'.$inscription->getStagiaire()?->getId()]);
                }
            }
        }
        $remaining = $people;
        foreach ($session->getSatisfactionAssignments() as $a) {
            if (!$a->getAttempt()?->isSubmitted()) continue;
            $key = $a->getStagiaire() ? 'user:'.$a->getStagiaire()->getId() : $a->getParticipantAccess()?->getSourceKey();
            unset($remaining[$key]);
        }
        if (!$expected && !$remaining) return null;
        return ['session' => $session, 'lastDate' => $last, 'attendance' => count($expected), 'satisfaction' => count($remaining)];
    }
}
