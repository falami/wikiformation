<?php
namespace App\Service\Marketplace;
use App\Service\Stripe\StripeClientFactory;
class StripeGateway {
 public function __construct(private StripeClientFactory $factory) {}
 public function account(string $id): array { return $this->factory->client()->accounts->retrieve($id,[])->toArray(); }
 public function createAccount(int $id,string $email): string { return $this->factory->client()->accounts->create(['type'=>'express','country'=>'FR','email'=>$email,'capabilities'=>['card_payments'=>['requested'=>true],'transfers'=>['requested'=>true]],'metadata'=>['marketplace_seller'=>(string)$id]],['idempotency_key'=>'market-seller-'.$id])->id; }
 public function onboarding(string $account,string $url): string { return $this->factory->client()->accountLinks->create(['account'=>$account,'type'=>'account_onboarding','refresh_url'=>$url,'return_url'=>$url])->url; }
 public function checkout(array $data,string $key): array { return $this->factory->client()->checkout->sessions->create($data,['idempotency_key'=>$key])->toArray(); }
 public function session(string $id): array { return $this->factory->client()->checkout->sessions->retrieve($id,[])->toArray(); }
 public function payment(string $id): array {
  $payment=$this->factory->client()->paymentIntents->retrieve($id,['expand'=>['latest_charge.balance_transaction']])->toArray();
  if (!empty($payment['latest_charge']['disputed'])) {
   foreach ($this->factory->client()->disputes->all(['charge'=>$payment['latest_charge']['id'],'limit'=>1])->data as $dispute) $payment['latest_charge']['_dispute_status']=$dispute->status;
  }
  return $payment;
 }
 public function findTransfer(string $key): ?array {
  $rows=$this->factory->client()->transfers->all(['transfer_group'=>$key,'limit'=>2])->data;
  if (count($rows)>1) throw new \DomainException('Plusieurs transferts Stripe correspondent au même versement : rapprochement requis.');
  return isset($rows[0]) ? $rows[0]->toArray() : null;
 }
 public function transferState(string $id): array { return $this->factory->client()->transfers->retrieve($id,[])->toArray(); }
 public function transfer(string $account,int $amount,string $charge,string $key): string { return $this->factory->client()->transfers->create(['destination'=>$account,'amount'=>$amount,'currency'=>'eur','source_transaction'=>$charge,'transfer_group'=>$key],['idempotency_key'=>$key])->id; }
 public function reverse(string $id,int $amount,string $key): void { $this->factory->client()->transfers->createReversal($id,['amount'=>$amount],['idempotency_key'=>$key]); }
}
