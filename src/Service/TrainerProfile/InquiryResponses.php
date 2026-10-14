<?php
namespace App\Service\TrainerProfile;
use App\Entity\{TrainerInquiry,Utilisateur,ProspectInteraction,NotificationEvent};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
final class InquiryResponses {
 public const LABELS=['available'=>'Disponible','not_interested'=>'Pas intéressé','unavailable'=>'Indisponible'];
 public function __construct(private EntityManagerInterface $em,#[Autowire('%kernel.secret%')] private string $secret){}
 public function signature(TrainerInquiry $item):string{return hash_hmac('sha256','trainer-inquiry:'.$item->id.':'.$item->requestKey,$this->secret);}
 public function valid(TrainerInquiry $item,string $token):bool{return hash_equals($this->signature($item),$token)&&$item->createdAt>new \DateTimeImmutable('-30 days')&&$item->status!=='closed';}
 public function record(TrainerInquiry $item,Utilisateur $actor,string $title,string $content):void {
  $tenant=$item->formateur->getEntite();$user=$item->formateur->getUtilisateur();
  $interaction=(new ProspectInteraction())->setEntite($tenant)->setUtilisateur($user)->setCreateur($actor)->setActor($actor)->setTitle(mb_substr($title,0,120))->setContent('Proposition #'.$item->id.' · '.$item->subject."\n".$content);$this->em->persist($interaction);
  $event=new NotificationEvent();$event->entite=$tenant;$event->subject=$user;$event->category='contracts';$event->title=mb_substr($title.' · '.$item->subject,0,255);$event->actor=trim($actor->getPrenom().' '.$actor->getNom());$event->sourceType='TrainerInquiry';$event->sourceId=$item->id;$this->em->persist($event);
 }
}
