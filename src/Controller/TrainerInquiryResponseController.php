<?php
namespace App\Controller;
use App\Entity\TrainerInquiry;
use App\Service\TrainerProfile\InquiryResponses;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response};
use Symfony\Component\Routing\Attribute\Route;
final class TrainerInquiryResponseController extends AbstractController {
 #[Route('/reponse-proposition/{id}/{token}',name:'app_trainer_inquiry_response',methods:['GET','POST'],requirements:['token'=>'[a-f0-9]{64}'])]
 public function respond(TrainerInquiry $item,string $token,Request $request,InquiryResponses $responses,EntityManagerInterface $em):Response {
  if(!$responses->valid($item,$token))return new Response('Ce lien est expiré ou cette proposition a été clôturée. Contactez votre organisme.',410,['Cache-Control'=>'no-store','Referrer-Policy'=>'no-referrer']);
  $choice=$request->isMethod('POST')?$request->request->getString('choice'):$request->query->getString('choice');$saved=false;$error=null;
  if($request->isMethod('POST')){
   if(!$this->isCsrfTokenValid('inquiry_response_'.$item->id,$request->request->get('_token')))throw $this->createAccessDeniedException();
   $note=trim($request->request->getString('note'));
   if(!isset(InquiryResponses::LABELS[$choice])||mb_strlen($note)>2000)$error='Choisissez une réponse et limitez votre commentaire à 2 000 caractères.';
   else {
    // Serialize replies so retries cannot duplicate the history and notifications.
    $em->getConnection()->beginTransaction();
    try{$em->refresh($item,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
     if(!$responses->valid($item,$token))throw $this->createAccessDeniedException('Proposition clôturée.');
     $last=$item->history?end($item->history):null;
     if($item->status!==$choice||($last['text']??'')!==(InquiryResponses::LABELS[$choice].($note!==''?' — '.$note:''))){
      $item->status=$choice;$actor=$item->formateur->getUtilisateur();$text=InquiryResponses::LABELS[$choice].($note!==''?' — '.$note:'');$item->history[]=['date'=>(new \DateTimeImmutable())->format(DATE_ATOM),'author'=>$actor->getPrenom().' '.$actor->getNom().' (lien e-mail)','text'=>$text,'status'=>$choice];
      $responses->record($item,$actor,'Réponse formateur : '.InquiryResponses::LABELS[$choice],$text."\nRéponse confirmée via le lien personnel de la proposition.");$em->flush();
     }$em->getConnection()->commit();$saved=true;
    }catch(\Throwable $e){$em->getConnection()->rollBack();throw $e;}
   }
  }
  $response=$this->render('formateur/profile/inquiry_response.html.twig',['item'=>$item,'choices'=>InquiryResponses::LABELS,'choice'=>$choice,'saved'=>$saved,'error'=>$error]);$response->headers->set('Cache-Control','no-store');$response->headers->set('Referrer-Policy','no-referrer');$response->headers->set('X-Robots-Tag','noindex, nofollow');return $response;
 }
}
