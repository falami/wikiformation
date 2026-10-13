<?php
declare(strict_types=1);
namespace App\Service\TrainerProfile;
use App\Entity\{TrainerProfile,Formateur,SessionJour};
use App\Enum\StatusSession;
use Doctrine\ORM\EntityManagerInterface;
final class Availability
{
    public function __construct(private EntityManagerInterface $em) {}
    public function days(Formateur $trainer, ?TrainerProfile $profile, \DateTimeImmutable $start, int $count=7): array {
        $end=$start->modify('+'.$count.' days');
        $jours=$this->em->createQueryBuilder()->select('j','s')->from(SessionJour::class,'j')->join('j.session','s')
            ->leftJoin('j.formateur','jf')->leftJoin('s.formateur','sf')
            ->where('(jf.utilisateur = :user OR (j.formateur IS NULL AND sf.utilisateur = :user))')
            ->andWhere('j.dateDebut < :end AND j.dateFin > :start')->andWhere('s.status != :cancel')
            ->setParameter('user',$trainer->getUtilisateur())->setParameter('start',$start)->setParameter('end',$end)->setParameter('cancel',StatusSession::CANCELED)->getQuery()->getResult();
        $days=[];
        for($i=0;$i<$count;$i++) {
            $day=$start->modify('+'.$i.' days'); $parts=[];
            foreach(['AM'=>[8,13],'PM'=>[13,18]] as $half=>[$from,$to]) {
                $a=$day->setTime($from,0); $b=$day->setTime($to,0); $state='unknown'; $label='À confirmer';
                $fresh=$profile?->calendarSecret && !$profile->syncError && $profile->syncedAt && $profile->syncedAt > new \DateTimeImmutable('-2 hours') && $profile->coveredUntil >= $b && $a >= $profile->syncedAt->setTime(0,0);
                if ($profile && in_array((int)$day->format('N'),$profile->workingDays,true) && ($fresh || (!$profile->calendarSecret && $profile->declaredAvailable))) { $state='free'; $label=$fresh?'Libre selon l’agenda':'Disponibilité déclarée'; }
                if ($profile && !in_array((int)$day->format('N'),$profile->workingDays,true)) { $state='off'; $label='Jour non travaillé'; }
                foreach ($profile?->busy ?? [] as $event) if ($profile?->calendarSecret && new \DateTimeImmutable($event['start']) < $b && new \DateTimeImmutable($event['end']) > $a) { $state='busy'; $label='Agenda externe occupé'; }
                foreach ($profile?->unavailable ?? [] as $block) if ($block['start'] <= $day->format('Y-m-d') && $block['end'] >= $day->format('Y-m-d') && in_array($block['period'],['DAY',$half],true)) { $state='busy'; $label='Indisponibilité déclarée'; }
                foreach($jours as $jour) if ($jour->getDateDebut()<$b && $jour->getDateFin()>$a) { $state='busy'; $label='Formation affectée'; }
                $parts[$half]=['state'=>$state,'label'=>$label];
            }
            $days[]=['date'=>$day->format('Y-m-d'),'parts'=>$parts];
        }
        return $days;
    }
}
