<?php
namespace App\Service;

use App\Entity\{Inscription, Emargement};
use Doctrine\ORM\EntityManagerInterface;

class AssiduiteCalculator
{
    public function __construct(private EntityManagerInterface $em) {}

    /** Une entrée par demi-journée réellement planifiée, sans doublons. */
    public function periods(Inscription $inscription): array
    {
        $periods = [];
        foreach ($inscription->getSession()->getJours() as $jour) {
            $start = $jour->getDateDebut(); $end = $jour->getDateFin();
            if (!$start || !$end || $end <= $start) continue;
            $date = $start->format('Y-m-d');
            if ($start->format('H:i') < '13:00') $periods[$date.':AM'] = ['date' => $date, 'label' => 'Matin'];
            if ($end->format('H:i') > '13:00') $periods[$date.':PM'] = ['date' => $date, 'label' => 'Après-midi'];
        }
        ksort($periods);
        return $periods;
    }

    public function online(Inscription $inscription): array
    {
        $signed = [];
        foreach ($this->em->getRepository(Emargement::class)->findBy([
            'session' => $inscription->getSession(), 'utilisateur' => $inscription->getStagiaire(), 'entite' => $inscription->getEntite(),
        ]) as $e) {
            if (in_array($e->getRole(), ['trainer', 'formateur'], true)) continue;
            if ($e->getSignedAt() || $e->getSignaturePath() || $e->getSignatureDataUrl()) {
                $signed[$e->getDateJour()->format('Y-m-d').':'.$e->getPeriode()->value] = true;
            }
        }
        return $signed;
    }

    /** Pas de taux déduit d'une absence de signature : les présences doivent être documentées. */
    public function computeForInscription(Inscription $inscription): ?float
    {
        if ($inscription->getSession()->isSousTraitance()) return null;
        $periods = $this->periods($inscription);
        $online = $this->online($inscription);
        $manual = $inscription->getPresencesManuelles();
        $present = 0;
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        foreach ($periods as $key => $period) {
            $status = $manual[$key]['status'] ?? 'unknown';
            if ($period['date'] > $today || (!isset($online[$key]) && !in_array($status, ['paper', 'absent'], true))) {
                $inscription->setTauxAssiduite(null);
                return null;
            }
            if (isset($online[$key]) || $status === 'paper') ++$present;
        }
        $pct = $periods ? round(100 * $present / count($periods), 1) : null;
        $inscription->setTauxAssiduite($pct);
        return $pct;
    }
}
