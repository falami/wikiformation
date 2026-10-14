<?php
namespace App\Service\TrainerProfile;
use App\Entity\TrainerInquiry;
use App\Service\Email\MailerManager;
use Sabre\VObject\Component\VCalendar;
use Twig\Environment;
final class InquiryCalendar {
 public function __construct(private MailerManager $mailer,private Environment $twig){}
 public function build(TrainerInquiry $item):string {
  $calendar=new VCalendar();$calendar->PRODID='-//Wiki Formation//Propositions formateurs//FR';$calendar->METHOD='PUBLISH';
  foreach(array_unique($item->slots) as $slot){
   $morning=str_ends_with($slot,'AM');$date=substr($slot,0,10);$start=new \DateTimeImmutable($date.($morning?' 08:00':' 13:00'),new \DateTimeZone('Europe/Paris'));$end=$start->setTime($morning?13:18,0);
   $calendar->add('VEVENT',[
    'UID'=>'inquiry-'.$item->id.'-'.substr(hash('sha256',$item->requestKey),0,16).'-'.$slot.'@wikiformation',
    'DTSTAMP'=>new \DateTimeImmutable('now',new \DateTimeZone('UTC')),
    'DTSTART'=>$start->setTimezone(new \DateTimeZone('UTC')),'DTEND'=>$end->setTimezone(new \DateTimeZone('UTC')),
    'SUMMARY'=>'[Provisoire] '.$item->subject.' · '.$item->formateur->getEntite()->getNom(),
    'DESCRIPTION'=>'Disponibilité acceptée pour la proposition #'.$item->id.'. Intervention à confirmer avec l’organisme. Horaires en heure de Paris. En cas de changement de réponse, modifiez ou supprimez ce créneau dans votre agenda. '. $item->message,
    'STATUS'=>'TENTATIVE','TRANSP'=>'OPAQUE',
   ]);
  }
  return $calendar->serialize();
 }
 public function send(TrainerInquiry $item):void {
  $text="Merci pour votre réponse positive. Le fichier calendrier joint contient les créneaux proposés pour ".$item->subject.". Ouvrez-le ou importez-le dans votre agenda. Ces créneaux restent provisoires, en attente de confirmation de l’organisme. Si votre réponse change, modifiez ou supprimez les événements importés.";
  $this->mailer->sendHtmlWithAttachment($item->formateur->getEntite(),$item->recipient,'Vos créneaux à ajouter à l’agenda · '.$item->subject,$this->twig->render('emails/trainer_inquiry_calendar.html.twig',['item'=>$item]),$text,$this->build($item),'proposition-'.$item->id.'.ics','text/calendar');
 }
}
