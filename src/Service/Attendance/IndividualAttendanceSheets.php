<?php
declare(strict_types=1);
namespace App\Service\Attendance;

use App\Entity\Session;
use App\Enum\StatusInscription;

/** One sheet per learner, with all days and their assigned trainers. */
final class IndividualAttendanceSheets
{
    public function build(Session $session, array $days, array $lines, iterable $accesses = []): array
    {
        $people = [];
        foreach ($session->getInscriptions() as $i) {
            $u = $i->getStagiaire();
            if (!$u || $i->getEntite()?->getId() !== $session->getEntite()?->getId() || $i->getStatus() === StatusInscription::ANNULE) continue;
            $id = 'user:'.$u->getId();
            $people[$id] = $this->blank($id, trim($u->getPrenom().' '.$u->getNom()), false, $i->getEntreprise()?->getRaisonSociale() ?? '—');
            $people[$id]['naissance'] = $u->getDateNaissance()?->format('d/m/Y') ?? '—';
        }
        foreach ($accesses as $access) {
            if (!$access->isGuest() || !$access->isActive() || $access->getEntite()?->getId() !== $session->getEntite()?->getId() || $access->getSession()?->getId() !== $session->getId()) continue;
            $id = 'guest:'.$access->getId();
            $people[$id] = $this->blank($id,$access->getDisplayName(),false,$access->getConvention()?->getEntreprise()?->getRaisonSociale() ?? '—');
        }
        // Include participants without accounts and historical signed participants.
        foreach ($lines as $rows) foreach ($rows as $row) if (!$row['isTrainer']) $people[$row['id']] ??= $this->blank($row['id'], $row['name'], false, $row['raisonSociale']);
        uasort($people, static fn($a,$b) => strcasecmp($a['name'],$b['name']));
        $sheets = [];
        foreach ($people as $id => $person) {
            $participantLines = [];
            foreach ($days as $day) {
                $date = new \DateTimeImmutable($day['ymd']);
                $rows = array_column($lines[$day['ymd']] ?? [], null, 'id');
                $selected = [$rows[$id] ?? $person];
                foreach ($session->getFormateursEffectifs() as $trainer) {
                    $u = $trainer->getUtilisateur();
                    if (!$u || $trainer->getEntite()?->getId() !== $session->getEntite()?->getId()) continue;
                    $am = $session->isFormateurUtilisateurSurPeriode($u, $date, 'AM');
                    $pm = $session->isFormateurUtilisateurSurPeriode($u, $date, 'PM');
                    if (!$am && !$pm) continue;
                    $tid = 'user:'.$u->getId();
                    $row = $rows[$tid] ?? $this->blank($tid,trim($u->getPrenom().' '.$u->getNom()),true,'—');
                    if (!$am) $row['am'] = ['signed'=>false,'img'=>null,'at'=>null];
                    if (!$pm) $row['pm'] = ['signed'=>false,'img'=>null,'at'=>null];
                    $selected[] = $row;
                }
                $participantLines[$day['ymd']] = $selected;
            }
            $sheets[] = ['id'=>$id, 'name'=>$person['name'], 'days'=>$days, 'lines'=>$participantLines];
        }
        return $sheets;
    }
    private function blank(string $id,string $name,bool $trainer,string $company): array
    {
        return ['id'=>$id,'name'=>$name,'isTrainer'=>$trainer,'raisonSociale'=>$company,'naissance'=>'—',
            'am'=>['signed'=>false,'img'=>null,'at'=>null], 'pm'=>['signed'=>false,'img'=>null,'at'=>null]];
    }
}
