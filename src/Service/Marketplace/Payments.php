<?php
namespace App\Service\Marketplace;
use App\Entity\{Entite,Utilisateur};
use App\Entity\Marketplace\{Seller,Purchase,Offer,Access};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
final class Payments {
 public function __construct(private EntityManagerInterface $em,private StripeGateway $stripe,private Policy $policy,private UrlGeneratorInterface $router,private Invoicing $invoicing) {}
 public static function split(int $gross,int $taxBps,int $commissionBps): array {
  if ($gross<0 || $taxBps<0 || $commissionBps<0 || $commissionBps>10000) throw new \InvalidArgumentException();
  $net=intdiv($gross*10000+intdiv(10000+$taxBps,2),10000+$taxBps);
  return ['net'=>$net,'tax'=>$gross-$net,'commission'=>intdiv($net*$commissionBps+5000,10000)];
 }
 public function onboard(Seller $seller,Entite $entite): string {
  if ($this->policy->platformSeller($seller->user)) throw new \DomainException('Vos ventes sont encaissées sur le compte Stripe de la plateforme. Aucun compte Connect supplémentaire n’est nécessaire.');
  BillingRules::seller($seller);
  if (!BillingRules::activeMandate($seller)) throw new \DomainException('Acceptez le mandat de facturation avant de configurer Stripe.');
  if (!$seller->termsAcceptedAt) throw new \DomainException('Complétez votre profil vendeur.');
  if (!$seller->accountId) { $seller->accountId=$this->stripe->createAccount($seller->id,$seller->email);$this->em->flush(); }
  return $this->stripe->onboarding($seller->accountId,$this->router->generate('app_market_trainer_seller',['entite'=>$entite->getId()],UrlGeneratorInterface::ABSOLUTE_URL));
 }
 public function checkout(Utilisateur $buyer,array $offerIds,array $consent): string {
  $settings=$this->policy->settings();
  if (!$settings->enabled || !$settings->legalApproved || $settings->commissionBps===null) throw new \DomainException('Les ventes ne sont pas encore ouvertes.');
  if (!$offerIds || count($offerIds)>30) throw new \DomainException('Votre panier est vide ou trop volumineux.');
  $billing=BillingRules::buyer($consent['billing']??[]);
  $purchase=new Purchase();$purchase->id=bin2hex(random_bytes(16));$purchase->buyer=$buyer;$purchase->createdAt=new \DateTimeImmutable();$purchase->commissionBps=$settings->commissionBps;$purchase->feesPayer=$settings->feesPayer;
  $purchase->consent=$consent+['acceptedAt'=>$purchase->createdAt->format(DATE_ATOM),'version'=>$settings->termsVersion,'terms'=>$settings->terms];
  foreach (array_unique($offerIds) as $id) {
   $offer=$this->em->find(Offer::class,$id);
   if (!$offer || !$offer->published || (!$offer->membersVisible && !$offer->publicVisible) || !$offer->snapshot || $offer->priceCents<50 || $offer->seller->user===$buyer) throw new \DomainException('Un cours du panier n’est plus disponible.');
   try { $this->policy->owns($offer->seller->user,$offer->course->getEntite(),$offer->course); } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) { throw new \DomainException('Ce vendeur ne peut plus proposer ce cours.'); }
   if ($this->em->getRepository(Access::class)->findOneBy(['buyer'=>$buyer,'course'=>$offer->course])) throw new \DomainException('Vous possédez déjà un cours de ce panier.');
   if ($purchase->seller && $purchase->seller!==$offer->seller) throw new \DomainException('Le règlement s’effectue par formateur.');
   $purchase->seller=$offer->seller;$purchase->platformSale=$this->policy->platformSeller($offer->seller->user);
   $purchase->commissionBps=$purchase->platformSale ? 0 : $settings->commissionBps;
   $purchase->feesPayer=$purchase->platformSale ? 'platform' : $settings->feesPayer;
   $split=self::split($offer->priceCents,$offer->taxBps,$purchase->commissionBps);
   $purchase->items[]=['offer'=>$offer->id,'course'=>$offer->course->getId(),'title'=>$offer->snapshot['title'],'gross'=>$offer->priceCents,'taxBps'=>$offer->taxBps,'net'=>$split['net'],'tax'=>$split['tax'],'commission'=>$split['commission'],'snapshot'=>$offer->snapshot];
   $purchase->totalCents+=$offer->priceCents;$purchase->commissionCents+=$split['commission'];
  }
  $seller=$purchase->seller;
  $identity=BillingRules::seller($seller);
  if ($purchase->platformSale) {
   if ($identity['registration']!==BillingRules::PLATFORM['registration'] || $identity['taxNumber']!==BillingRules::PLATFORM['taxNumber'] || $identity['taxExemption']) throw new \DomainException('Les ventes propres doivent utiliser l’identité fiscale d’I.T. For You.');
   $identity=BillingRules::PLATFORM;
  }
  $mandate=BillingRules::activeMandate($seller);
  if (!$purchase->platformSale && (!$mandate || ($mandate['seller']??[])!=$identity)) throw new \DomainException('Le vendeur doit accepter le mandat de facturation avec ses coordonnées à jour.');
  foreach ($purchase->items as $item) {
   if ($item['taxBps']!==($identity['taxExemption'] ? 0 : 2000)) throw new \DomainException('Le taux de TVA du cours ne correspond pas au régime fiscal déclaré par le vendeur.');
  }
  $purchase->billingVersion=1;
  $purchase->commissionTaxCents=intdiv($purchase->commissionCents*2000+5000,10000);
  if ($purchase->commissionGrossCents()>$purchase->totalCents) throw new \DomainException('La commission TTC dépasse le prix du cours. Contactez la plateforme.');
  $purchase->consent['billing']=$billing;
  $purchase->consent['platform']=BillingRules::PLATFORM;
  $purchase->consent['mandate']=$mandate;

  if ((!$purchase->platformSale && !$seller->accountId) || !$seller->termsAcceptedAt || $seller->termsVersion!==$settings->termsVersion) throw new \DomainException('Ce vendeur doit terminer son activation avant de pouvoir recevoir des paiements.');
  if (!$purchase->platformSale) {
   $account=$this->stripe->account($seller->accountId);
   if (($account['country']??'')!=='FR') throw new \DomainException('Le compte Stripe du vendeur doit être établi en France.');
   if (empty($account['charges_enabled']) || empty($account['payouts_enabled'])) throw new \DomainException('Les paiements de ce vendeur ne sont pas encore activés.');
  }
  // Serialize cart creation; a pending checkout must be resolved before purchasing the same course again.
  $this->em->wrapInTransaction(function() use($buyer,$purchase,$identity) {
   $this->em->lock($buyer,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
   foreach ($purchase->items as $item) { if ($this->em->getRepository(Access::class)->findOneBy(['buyer'=>$buyer,'course'=>$item['course']])) throw new \DomainException('Vous possédez déjà ce cours.'); }
   foreach ($this->em->getRepository(Purchase::class)->findBy(['buyer'=>$buyer,'status'=>'pending']) as $existing) {
    if ($existing->createdAt < new \DateTimeImmutable('-31 minutes')) continue;
    if (array_intersect(array_column($existing->items,'course'),array_column($purchase->items,'course'))) throw new \DomainException('Un paiement est déjà en cours pour ce cours. Retrouvez-le dans vos achats ou attendez son expiration (30 minutes).');
   }
   $purchase->consent['seller']=$identity;
   $this->em->persist($purchase);$this->em->flush();
  });
  $lines=[];foreach ($purchase->items as $item) $lines[]=['quantity'=>1,'price_data'=>['currency'=>'eur','unit_amount'=>$item['gross'],'product_data'=>['name'=>$item['title']]]];
  $url=$this->router->generate('app_market_purchase',['id'=>$purchase->id],UrlGeneratorInterface::ABSOLUTE_URL);
  $purchase->checkoutRequest=['mode'=>'payment','payment_method_types'=>['card'],'line_items'=>$lines,'customer_email'=>$buyer->getEmail(),'billing_address_collection'=>'required','expires_at'=>time()+1800,'metadata'=>['marketplace_purchase'=>$purchase->id],'payment_intent_data'=>['on_behalf_of'=>$seller->accountId,'transfer_group'=>'market-'.$purchase->id,'metadata'=>['marketplace_purchase'=>$purchase->id]],'success_url'=>$url,'cancel_url'=>$url];
  if ($purchase->platformSale) unset($purchase->checkoutRequest['payment_intent_data']['on_behalf_of'],$purchase->checkoutRequest['payment_intent_data']['transfer_group']);
  $this->em->flush();return $this->resume($purchase);
 }
 public function resume(Purchase $purchase): string {
  if ($purchase->status!=='pending' || !$purchase->checkoutRequest || ($purchase->checkoutRequest['expires_at']??0)<=time()+60) throw new \DomainException('Ce paiement a expiré. Créez un nouveau panier.');
  if (!$this->policy->settings()->enabled) throw new \DomainException('Les ventes sont suspendues.');
  if ($purchase->checkoutUrl) return $purchase->checkoutUrl;
  $checkout=$this->stripe->checkout($purchase->checkoutRequest,'market-checkout-'.$purchase->id);
  $purchase->checkoutId=$checkout['id'];$purchase->checkoutUrl=$checkout['url'];$this->em->flush();return $purchase->checkoutUrl;
 }
 public function sync(Purchase $purchase): void {
  if (!$purchase->checkoutId) return;
  $this->em->wrapInTransaction(function() use($purchase) {
   $this->em->lock($purchase,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);$this->em->refresh($purchase);
  $session=$this->stripe->session($purchase->checkoutId);
  if (($session['metadata']['marketplace_purchase']??'')!==$purchase->id || ($session['amount_total']??-1)!==$purchase->totalCents || ($session['currency']??'')!=='eur') throw new \DomainException('Paiement incohérent.');
  if (($session['status']??'')==='expired' && $purchase->status==='pending') {$purchase->status='expired';$this->em->flush();return;}
  if (($session['payment_status']??'')!=='paid') return;
  $payment=$this->stripe->payment($session['payment_intent']);$charge=$payment['latest_charge']??[];
  if (($payment['status']??'')!=='succeeded' || ($payment['currency']??'')!=='eur' || ($payment['amount_received']??-1)!==$purchase->totalCents || !is_array($charge)) return;
   $this->em->lock($purchase->buyer,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
   $purchase->paymentId=$session['payment_intent'];$purchase->chargeId=$charge['id'];
   $purchase->paidAt ??= new \DateTimeImmutable('@'.($charge['created']??time()));
   $purchase->refundedCents=(int)($charge['amount_refunded']??0);
   $purchase->status=!empty($charge['disputed']) && ($charge['_dispute_status']??'')!=='won'?'disputed':($purchase->refundedCents>0?'refunded':'paid');
   $balance=$charge['balance_transaction']??null;
   if (is_array($balance)) $purchase->stripeFeeCents=(int)$balance['fee'];
   $purchase->sellerCents=$purchase->platformSale ? 0 : max(0,$purchase->totalCents-$purchase->commissionGrossCents()-($purchase->feesPayer==='seller'?$purchase->stripeFeeCents:0));
   foreach ($purchase->items as $item) {
    $course=$this->em->find(\App\Entity\Elearning\ElearningCourse::class,$item['course']);
    $access=$this->em->getRepository(Access::class)->findOneBy(['buyer'=>$purchase->buyer,'course'=>$course]);
    if (!$access) { $access=new Access();$access->buyer=$purchase->buyer;$access->course=$course;$access->createdAt=new \DateTimeImmutable();$access->snapshot=$item['snapshot'];$access->purchase=$purchase;$this->em->persist($access); }
    // A dispute pauses access; partial refunds keep the complete purchased course accessible.
    if ($access->purchase===$purchase) $access->suspended=$purchase->status==='disputed' || $purchase->refundedCents===$purchase->totalCents;
   }
   $this->invoicing->issue($purchase);
   $this->em->flush();
  });
 }
 public function settle(Purchase $purchase): void {
  $this->em->wrapInTransaction(function() use ($purchase) {
   $this->em->lock($purchase,\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);$this->em->refresh($purchase);
   $this->settleLocked($purchase);
  });
 }
 private function settleLocked(Purchase $purchase): void {
  $this->sync($purchase);
  if ($purchase->platformSale) return;
  if (!$purchase->paidAt || $purchase->paidAt>new \DateTimeImmutable('-14 days') || !$purchase->chargeId) return;
  $remainingCommission=max(0,$purchase->commissionCents-Invoicing::refundShare($purchase->commissionCents,$purchase->refundedCents,$purchase->totalCents))+max(0,$purchase->commissionTaxCents-Invoicing::refundShare($purchase->commissionTaxCents,$purchase->refundedCents,$purchase->totalCents));
  $desired = $purchase->status==='disputed' ? 0 : max(0,$purchase->totalCents-$purchase->refundedCents-$remainingCommission-($purchase->feesPayer==='seller'?$purchase->stripeFeeCents:0));
  $delta=$desired-$purchase->transferredCents;
  $key='market-settle-'.$purchase->id.'-'.count($purchase->payoutHistory);
  // A previous POST may have succeeded before a SQL rollback. Stripe retains keys for only 24 hours.
   // Recover the durable transfer before retrying, even when sales have since been suspended.
   $found=$this->stripe->findTransfer($key);
   if ($found) {
    if (($found['currency']??'')!=='eur' || ($found['destination']??'')!==$purchase->seller->accountId
      || ($found['source_transaction']??'')!==$purchase->chargeId || ($found['amount']??0)<=0 || $found['amount']>$purchase->totalCents) throw new \DomainException('Transfert Stripe incompatible : rapprochement requis.');
    $reversed=(int)($found['amount_reversed']??0);
    if ($reversed<0 || $reversed>$found['amount']) throw new \DomainException('Reprise Stripe incohérente.');
    $purchase->transferId=$found['id'];$purchase->payoutHistory[]=['id'=>$found['id'],'amount'=>$found['amount'],'reversed'=>$reversed];
    $purchase->transferredCents+=$found['amount']-$reversed;$this->em->flush();
    $this->settleLocked($purchase);return;
   }
  if ($delta>0) {
   if (!$this->policy->settings()->enabled) return;
   $payment=$this->stripe->payment($purchase->paymentId);$balance=$payment['latest_charge']['balance_transaction']??null;
   if (!is_array($balance) || ($balance['available_on']??PHP_INT_MAX)>time()) return;
   $id=$this->stripe->transfer($purchase->seller->accountId,$delta,$purchase->chargeId,$key);
   $purchase->transferId=$id;$purchase->payoutHistory[]=['id'=>$id,'amount'=>$delta,'reversed'=>0];$purchase->transferredCents+=$delta;
   $this->em->flush();
  } elseif ($delta<0) {
   $remaining=-$delta;
   foreach ($purchase->payoutHistory as $index=>$entry) {
    // Reconcile reversals as well: replaying a pruned idempotency key must never take money twice.
    $remote=$this->stripe->transferState($entry['id']);
    $remoteReversed=(int)($remote['amount_reversed']??-1);
    if (($remote['amount']??-1)!==$entry['amount'] || ($remote['currency']??'')!=='eur'
      || ($remote['destination']??'')!==$purchase->seller->accountId || $remoteReversed<$entry['reversed'] || $remoteReversed>$entry['amount']) throw new \DomainException('Reprise Stripe incompatible : rapprochement requis.');
    $recovered=$remoteReversed-$entry['reversed'];
    if ($recovered>0) {
     $purchase->payoutHistory[$index]['reversed']=$remoteReversed;$purchase->transferredCents-=$recovered;
     $remaining=max(0,$remaining-$recovered);$entry['reversed']=$remoteReversed;$this->em->flush();
     if ($remaining===0) break;
    }
    $amount=min($remaining,$entry['amount']-$entry['reversed']);if ($amount<=0) continue;
    $this->stripe->reverse($entry['id'],$amount,'market-reverse-'.$purchase->id.'-'.$index.'-'.($entry['reversed']+$amount));
    $purchase->payoutHistory[$index]['reversed']+=$amount;$purchase->transferredCents-=$amount;$remaining-=$amount;$this->em->flush();
    if ($remaining===0) break;
   }
  }
 }
}
