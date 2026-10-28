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
            'session' => $inscription->getSession(), 'entite' => $inscription->getEntite(),
        ]) as $e) {
            if (in_array($e->getRole(), ['trainer', 'formateur'], true)) continue;
            $linked = $e->getParticipantAccess()?->getInscription();
            $sameUser = $e->getUtilisateur() && $e->getUtilisateur()->getId() === $inscription->getStagiaire()?->getId();
            $sameInscription = $linked && $linked->getId() === $inscription->getId();
            if (!$sameUser && !$sameInscription) continue;
            if ($e->getSignedAt() || $e->getSignaturePath() || $e->getSignatureDataUrl()) {
                $signed[$e->getDateJour()->format('Y-m-d').':'.$e->getPeriode()->value] = true;
            }
        }
        return $signed;
    }

    /** Pas de taux déduit d'une absence de signature : les présences doivent être documentées. */
    public function computeForInscription(Inscription $inscription): ?float
    {
        $rate = $this->summary($inscription)['rate'];
        if (!$inscription->getSession()->isSousTraitance()) $inscription->setTauxAssiduite($rate);
        return $rate;
    }

    /** Prévisualisation sans modifier l'inscription ; une signature manquante n'est pas une absence. */
    public function summary(Inscription $inscription): array
    {
        $periods = $this->periods($inscription);
        $online = $this->online($inscription);
        $manual = $inscription->getPresencesManuelles();
        $present = $absent = $unknown = $future = 0;
        $details = [];
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        foreach ($periods as $key => $period) {
            $status = $manual[$key]['status'] ?? 'unknown';
            if ($period['date'] > $today) { $state = 'À venir'; ++$future; }
            elseif (isset($online[$key])) { $state = 'Présent · signé en ligne'; ++$present; }
            elseif ($status === 'paper') { $state = 'Présent · feuille papier'; ++$present; }
            elseif ($status === 'absent') { $state = 'Absent'; ++$absent; }
            else { $state = 'Non renseigné'; ++$unknown; }
            $details[] = $period + ['state'=>$state];
        }
        $total = count($periods);
        $rate = !$inscription->getSession()->isSousTraitance() && $total && !$unknown && !$future
            ? round(100 * $present / $total, 1) : null;
        return compact('rate','present','absent','unknown','future','total','details');
    }
}
