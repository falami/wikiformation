<?php
namespace App\Service\Marketplace;
use App\Entity\Marketplace\Purchase;
use App\Entity\Elearning\ElearningCourse;
use App\Service\Email\MailerManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class Confirmations {
 public function __construct(private EntityManagerInterface $em,private MailerManager $mailer,private UrlGeneratorInterface $router) {}
 public function send(Purchase $purchase): void {
  $this->em->wrapInTransaction(function() use ($purchase) {
   $this->em->lock($purchase,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);$this->em->refresh($purchase);
   $this->sendLocked($purchase);
   $this->sendDocuments($purchase);
  });
 }
 private function sendDocuments(Purchase $purchase): void {
  if (!$purchase->paidAt || $purchase->billingVersion!==1) return;
  $course=$this->em->find(ElearningCourse::class,$purchase->items[0]['course']);
  foreach ($this->em->getRepository(\App\Entity\Marketplace\Invoice::class)->findBy(['purchase'=>$purchase],['id'=>'ASC']) as $invoice) {
   $url=$this->router->generate('app_market_invoices',[],UrlGeneratorInterface::ABSOLUTE_URL);
   $text="Votre document comptable ".$invoice->number." est disponible dans votre espace sécurisé : ".$url."\nConservez cette copie pour votre comptabilité.";
   if ($invoice->document['mandated']) $text.="\nIl a été émis en votre nom et pour votre compte. Vérifiez-le : vous disposez de trente jours à compter de cette notification pour signaler une erreur dans votre espace ou à contact@itforyou.fr, conformément au mandat accepté.";
   $recipient=$purchase->consent['seller']['email'];
   if (!$invoice->notifiedAt) {
    $this->mailer->sendParticipantReminder($course->getEntite(),$recipient,'Document comptable · '.$invoice->number,'<p>'.nl2br(htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>',$text,[]);
    $invoice->notifiedAt=new \DateTimeImmutable();$this->em->flush();
   }
   if (str_starts_with($invoice->kind,'sale') && !$invoice->buyerNotifiedAt) {
    $buyerText='Votre '.($invoice->kind==='sale'?'facture':'avoir').' '.$invoice->number.' est disponible dans votre espace sécurisé : '.$url;
    $this->mailer->sendParticipantReminder($course->getEntite(),$purchase->buyer->getEmail(),'Votre document · '.$invoice->number,'<p>'.htmlspecialchars($buyerText,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>',$buyerText,[]);
    $invoice->buyerNotifiedAt=new \DateTimeImmutable();$this->em->flush();
   }
  }
 }
 private function sendLocked(Purchase $purchase): void {
  if (!$purchase->paidAt || $purchase->confirmationSentAt) return;
  $course=$this->em->find(ElearningCourse::class,$purchase->items[0]['course']);
  $url=$this->router->generate('app_market_purchase',['id'=>$purchase->id],UrlGeneratorInterface::ABSOLUTE_URL);
  // Contractual identity is frozen at checkout; never substitute the seller's current profile.
  $seller=$purchase->consent['seller']??[];
  $text="Bonjour,\nVotre commande ".$purchase->id." est confirmée.\n\nCoordonnées du vendeur au moment de la commande\nVendeur : ".($seller['name']??'Non renseigné')."\n";
  foreach (['registration'=>'Immatriculation','address'=>'Adresse professionnelle','email'=>'E-mail de contact','taxNumber'=>'Numéro de TVA','taxExemption'=>'Mention d’exonération de TVA'] as $field=>$label) {
   if (trim((string)($seller[$field]??''))!=='') $text.=$label.' : '.$seller[$field]."\n";
  }
  $text.="\nVos cours\n";
  foreach ($purchase->items as $item) $text.=$item['title'].' : '.number_format($item['gross']/100,2,',',' ')." € TTC\n";
  $text.="\nVous avez demandé expressément l’accès immédiat aux contenus numériques et reconnu la perte du droit de rétractation dès le début de leur fourniture. Vos garanties légales restent applicables.\nAccord enregistré le ".$purchase->consent['acceptedAt'].".\n\nRetrouvez votre facture, votre récapitulatif et vos cours : ".$url."\n\nConditions acceptées (".$purchase->consent['version'].") :\n".$purchase->consent['terms'];
  $html='<div style="background:#eef2f6;padding:32px;font-family:Arial;color:#223342"><div style="max-width:640px;margin:auto;background:white;border-radius:20px;overflow:hidden"><header style="padding:32px;background:#223342;color:white;border-top:5px solid #ffbf00"><strong>WikiFormation</strong><h1>Votre prochain chapitre commence.</h1></header><div style="padding:32px;line-height:1.7">'.nl2br(htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</div></div></div>';
  $this->mailer->sendParticipantReminder($course->getEntite(),$purchase->buyer->getEmail(),'Votre achat de cours · '.$purchase->id,$html,$text,[]);
  $purchase->confirmationSentAt=new \DateTimeImmutable();$this->em->flush();
 }
}
