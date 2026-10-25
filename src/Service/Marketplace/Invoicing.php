<?php
declare(strict_types=1);
namespace App\Service\Marketplace;

use App\Entity\Marketplace\{Invoice,Purchase,Settings};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Dompdf\{Dompdf,Options};
use Twig\Environment;

final class Invoicing
{
    public function __construct(private EntityManagerInterface $em, private Environment $twig) {}

    /** Called inside the payment transaction, after Stripe has been verified. */
    public function issue(Purchase $purchase): void
    {
        if (!$purchase->paidAt || $purchase->billingVersion!==1) return;
        if (!$this->em->getConnection()->isTransactionActive()) throw new \LogicException('Facturation hors transaction.');
        // A permanent row serializes all document counters, including their first use.
        $settings=$this->em->find(Settings::class,1);
        if (!$settings) throw new \LogicException('Réglages de facturation absents.');
        $this->em->lock($settings,LockMode::PESSIMISTIC_WRITE);
        $sale=$this->find($purchase,'sale');
        if (!$sale) {
            $sale=$this->create($purchase,'sale','sale',$purchase->consent['seller'],$purchase->consent['billing'], $purchase->items);
        }
        $commission=$this->find($purchase,'commission');
        if (!$purchase->platformSale && $purchase->commissionCents>0 && !$commission) {
            $commission=$this->create($purchase,'commission','commission',$purchase->consent['platform'],$purchase->consent['seller'],[
                ['title'=>'Commission de diffusion et de vente — commande '.$purchase->id,'net'=>$purchase->commissionCents,'tax'=>$purchase->commissionTaxCents,'gross'=>$purchase->commissionGrossCents(),'taxBps'=>2000]
            ]);
        }
        $refunded=$purchase->refundedCents;
        if ($refunded<0 || $refunded>$purchase->totalCents) throw new \DomainException('Remboursement incohérent.');
        $previous=0;
        foreach ($this->em->getRepository(Invoice::class)->findBy(['purchase'=>$purchase,'kind'=>'sale_credit']) as $credit) $previous=max($previous,$credit->document['refundedTotal']);
        if ($refunded<$previous) throw new \DomainException('Le total remboursé ne peut pas diminuer.');
        if ($refunded===$previous) return;
        // All lines have the seller's single declared VAT regime. Credit the order total
        // cumulatively, avoiding rounding drift when Stripe reports several partial refunds.
        $netTotal=(int)$sale->document['net'];
        $net=intdiv($netTotal*$refunded,$purchase->totalCents)-intdiv($netTotal*$previous,$purchase->totalCents);
        $gross=$refunded-$previous;
        $lines=[['title'=>'Remboursement des cours — commande '.$purchase->id,'net'=>$net,'tax'=>$gross-$net,'gross'=>$gross,'taxBps'=>$purchase->items[0]['taxBps']]];
        $this->create($purchase,'sale-credit-'.$refunded,'sale_credit',$sale->document['issuer'],$sale->document['customer'],$lines,$sale->number,$refunded);
        if ($commission) {
            $net=self::refundShare($purchase->commissionCents,$refunded,$purchase->totalCents)-self::refundShare($purchase->commissionCents,$previous,$purchase->totalCents);
            $tax=self::refundShare($purchase->commissionTaxCents,$refunded,$purchase->totalCents)-self::refundShare($purchase->commissionTaxCents,$previous,$purchase->totalCents);
            if ($net+$tax>0) $this->create($purchase,'commission-credit-'.$refunded,'commission_credit',$commission->document['issuer'],$commission->document['customer'],[
                ['title'=>'Régularisation de commission — remboursement de commande','net'=>$net,'tax'=>$tax,'gross'=>$net+$tax,'taxBps'=>2000]
            ],$commission->number,$refunded);
        }
    }
    public static function refundShare(int $amount,int $refund,int $total): int
    {
        return intdiv($amount*$refund+max(1,$total)-1,max(1,$total));
    }
    private function find(Purchase $purchase,string $key): ?Invoice
    {
        return $this->em->getRepository(Invoice::class)->findOneBy(['purchase'=>$purchase,'eventKey'=>$key]);
    }
    private function create(Purchase $purchase,string $key,string $kind,array $issuer,array $customer,array $lines,?string $original=null,int $refunded=0): Invoice
    {
        if ($existing=$this->find($purchase,$key)) return $existing;
        $invoice=new Invoice();$invoice->purchase=$purchase;$invoice->eventKey=$key;$invoice->kind=$kind;
        $invoice->issuedAt=new \DateTimeImmutable('now',new \DateTimeZone('Europe/Paris'));
        $invoice->series='MK-'.(str_starts_with($kind,'commission') || $purchase->platformSale ? 'P' : 'S'.$purchase->seller->id).'-'.$invoice->issuedAt->format('Y');
        $invoice->sequence=1+(int)$this->em->getConnection()->fetchOne('SELECT MAX(sequence) FROM marketplace_invoice WHERE series = ?',[$invoice->series]);
        $invoice->number=$invoice->series.'-'.str_pad((string)$invoice->sequence,6,'0',STR_PAD_LEFT);
        $invoice->document=[
            'issuer'=>$issuer,'customer'=>$customer,'lines'=>array_map(fn($i)=>array_intersect_key($i,array_flip(['title','net','tax','gross','taxBps'])),$lines),
            'net'=>array_sum(array_column($lines,'net')),'tax'=>array_sum(array_column($lines,'tax')),'gross'=>array_sum(array_column($lines,'gross')),
            'paidAt'=>$purchase->paidAt->format(DATE_ATOM),'reference'=>$purchase->id,'original'=>$original,'refundedTotal'=>$refunded,
            'mandated'=>!$purchase->platformSale && str_starts_with($kind,'sale'),
        ];
        $options=new Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('defaultFont','DejaVu Sans');
        $pdf=new Dompdf($options);$pdf->loadHtml($this->twig->render('marketplace/invoice.pdf.html.twig',['invoice'=>$invoice]),'UTF-8');$pdf->setPaper('A4');$pdf->render();
        $bytes=$pdf->output();$invoice->pdf=base64_encode($bytes);$invoice->sha256=hash('sha256',$bytes);
        $this->em->persist($invoice);$this->em->flush();
        return $invoice;
    }
}
