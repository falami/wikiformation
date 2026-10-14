<?php
namespace App\Controller;
use App\Entity\{Entite,Formateur,TrainerInquiry};
use App\Security\Permission\TenantPermission;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
#[IsGranted(TenantPermission::PLANNING_FORMATEUR_MANAGE,subject:'entite')]
final class TrainerInquiryController extends AbstractController {
 #[Route('/administrateur/{entite}/formateurs/{id}/demandes',name:'app_trainer_inquiries',methods:['GET','POST'])]
 public function inquiries(Entite $entite,Formateur $trainer,Request $request,EntityManagerInterface $em,MailerManager $mailer,\App\Service\TrainerProfile\InquiryResponses $responses):Response {
  if($trainer->getEntite()?->getId()!==$entite->getId())throw $this->createNotFoundException();
  if($request->isMethod('POST')){
   if(!$this->isCsrfTokenValid('inquiry_'.$entite->getId(),$request->request->get('_token')))throw $this->createAccessDeniedException();
   $subject=trim($request->request->getString('subject'));$message=trim($request->request->getString('message'));$key=$request->request->getString('key');$rawSlots=$request->request->all('slots');if(count($rawSlots)>60||array_filter($rawSlots,fn($slot)=>!is_string($slot)))return $this->json(['error'=>'Sélection de créneaux invalide.'],422);$slots=array_values(array_unique($rawSlots));
   if(!preg_match('/^[a-f0-9]{32}$/',$key)||!$subject||mb_strlen($subject)>100||mb_strlen($message)>5000||!count($slots)||count($slots)>60)return $this->json(['error'=>'Renseignez une matière, 1 à 60 demi-journées et un message de 5 000 caractères maximum.'],422);
   $today=new \DateTimeImmutable('today',new \DateTimeZone('Europe/Paris'));
   foreach($slots as $slot){if(!is_string($slot)||!preg_match('/^(\d{4}-\d{2}-\d{2})-(AM|PM)$/',$slot,$m))return $this->json(['error'=>'Créneau invalide.'],422);$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$m[1],new \DateTimeZone('Europe/Paris'));if(!$date||$date->format('Y-m-d')!==$m[1]||$date<$today||$date>$today->modify('+2 years'))return $this->json(['error'=>'Choisissez des dates à venir, dans les deux prochaines années.'],422);}
   $requestKey=hash('sha256',$entite->getId().':'.$trainer->getId().':'.$key);
   if($existing=$em->getRepository(TrainerInquiry::class)->findOneBy(['requestKey'=>$requestKey]))return $this->json(['ok'=>true,'status'=>$existing->status]);
   $recipient=$trainer->getUtilisateur()?->getEmail();if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))return $this->json(['error'=>'Le formateur ne possède pas une adresse e-mail valide.'],422);
   sort($slots);$item=new TrainerInquiry();$item->formateur=$trainer;$item->author=$this->getUser();$item->subject=$subject;$item->recipient=$recipient;$item->slots=$slots;$item->message=$message;$item->requestKey=$requestKey;
   $item->history[]=$this->event('Demande préparée');$em->persist($item);$em->flush();
   $dates=implode("\n",array_map(fn($s)=>substr($s,8,2).'/'.substr($s,5,2).'/'.substr($s,0,4).' · '.(str_ends_with($s,'AM')?'Matin (8 h–13 h)':'Après-midi (13 h–18 h)'),$slots));
   $text="Bonjour ".$trainer->getUtilisateur()->getPrenom().",\n\n".$entite->getNom()." souhaite vous proposer une intervention sur la matière : ".$subject.".\n\nCréneaux proposés (heure de Paris) :\n".$dates."\n\n".$message."\n\nSeriez-vous intéressé(e) et disponible ? Vous pouvez répondre directement à ce message. Cette demande ne constitue pas une réservation.\n\n".$entite->getNom();
   $responseUrl=$this->generateUrl('app_trainer_inquiry_response',['id'=>$item->id,'token'=>$responses->signature($item)],\Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
   $text.="\n\nRépondre à la proposition : ".$responseUrl;
   try{$mailer->sendHtml($entite,$recipient,'Proposition de formation · '.$subject,$this->renderView('emails/trainer_inquiry.html.twig',['entite'=>$entite,'item'=>$item,'responseUrl'=>$responseUrl,'choices'=>\App\Service\TrainerProfile\InquiryResponses::LABELS]),$text);$item->status='sent';$item->history[]=$this->event('Demande transmise au service d’envoi');}catch(\Throwable){$item->status='failed';$item->history[]=$this->event('Échec de transmission. Vérifiez la configuration e-mail avant une nouvelle demande.');}
   $responses->record($item,$this->getUser(),$item->status==='sent'?'Proposition de formation transmise':'Échec d’envoi de la proposition',$dates."\n".$message);$em->flush();return $this->json(['ok'=>true,'status'=>$item->status]);
  }
  $items=$em->getRepository(TrainerInquiry::class)->findBy(['formateur'=>$trainer],['createdAt'=>'DESC'],100);
  return $this->json(['items'=>array_map(fn($i)=>['id'=>$i->id,'subject'=>$i->subject,'recipient'=>$i->recipient,'slots'=>$i->slots,'message'=>$i->message,'status'=>$i->status,'createdAt'=>$i->createdAt->format(DATE_ATOM),'history'=>$i->history,'updateUrl'=>$this->generateUrl('app_trainer_inquiry_update',['entite'=>$entite->getId(),'id'=>$i->id])],$items)]);
 }
 #[Route('/administrateur/{entite}/demandes-formateur/{id}',name:'app_trainer_inquiry_update',methods:['POST'])]
 public function update(Entite $entite,TrainerInquiry $item,Request $request,EntityManagerInterface $em,\App\Service\TrainerProfile\InquiryResponses $responses):Response {
  if($item->formateur->getEntite()?->getId()!==$entite->getId())throw $this->createNotFoundException();
  if(!$this->isCsrfTokenValid('inquiry_'.$entite->getId(),$request->request->get('_token')))throw $this->createAccessDeniedException();
  $note=trim($request->request->getString('note'));$status=$request->request->getString('status');
  if(!in_array($status,['interested','declined','discussing','closed'],true)||!$note||mb_strlen($note)>3000||count($item->history)>=200)return $this->json(['error'=>'Ajoutez une note (3 000 caractères maximum) et un statut valide.'],422);
  $item->status=$status;$item->history[]=$this->event($note,$status);$responses->record($item,$this->getUser(),'Suivi de proposition : '.$status,$note);$em->flush();return $this->json(['ok'=>true]);
 }
 private function event(string $text,?string $status=null):array{return ['date'=>(new \DateTimeImmutable())->format(DATE_ATOM),'author'=>$this->getUser()->getPrenom().' '.$this->getUser()->getNom(),'text'=>$text,'status'=>$status];}
}
