<?php
namespace App\Service\Satisfaction;
use App\Entity\{Entite,SatisfactionAttempt,Formateur};
use Doctrine\ORM\EntityManagerInterface;
final class CatalogueRatings {
 public function __construct(private EntityManagerInterface $em){}
 public function forType(Entite $tenant,string $type):array {
  $field=match($type){'formation'=>'noteGlobale','formateur'=>'noteFormateur','site'=>'noteSite',default=>throw new \InvalidArgumentException()};
  $qb=$this->em->createQueryBuilder()->from(SatisfactionAttempt::class,'a')->join('a.assignment','assignment')->join('assignment.session','s')->where('a.entite = :tenant AND assignment.entite = :tenant AND s.entite = :tenant')->andWhere('a.submittedAt IS NOT NULL')->andWhere('a.'.$field.' BETWEEN 0 AND 10')->setParameter('tenant',$tenant);
  if($type==='formateur'){
   $qb->join(Formateur::class,'target','WITH','target.entite = :tenant AND ((s.formateur = target AND (NOT EXISTS (SELECT j0.id FROM App\Entity\SessionJour j0 WHERE j0.session = s) OR EXISTS (SELECT j1.id FROM App\Entity\SessionJour j1 WHERE j1.session = s AND j1.formateur IS NULL))) OR EXISTS (SELECT j2.id FROM App\Entity\SessionJour j2 WHERE j2.session = s AND j2.formateur = target))');
  }else{$qb->join('s.'.$type,'target')->andWhere('target.entite = :tenant');}
  $rows=$qb->select('target.id AS id, AVG(a.'.$field.') AS average, COUNT(a.id) AS votes')->groupBy('target.id')->getQuery()->getArrayResult();$map=[];
  foreach($rows as $row)$map[(int)$row['id']]=['average'=>round((float)$row['average'],1),'votes'=>(int)$row['votes']];return $map;
 }
}
