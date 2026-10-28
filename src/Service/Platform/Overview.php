<?php
declare(strict_types=1);
namespace App\Service\Platform;
use App\Entity\{Entite, UtilisateurEntite, Facture, Paiement, EInvoiceUsage};
use App\Entity\Billing\{EntiteSubscription, FactureCheckout};
use App\Entity\Marketplace\Purchase;
use App\Entity\Partner\{Referral, Commission};
use App\Entity\Platform\{Revenue, Cost};
use Doctrine\ORM\EntityManagerInterface;

final class Overview
{
    public const ACTIVITIES = ['all'=>'Toutes les activités', 'subscriptions'=>'Abonnements & usages', 'courses'=>'Formations en ligne', 'invoices'=>'Paiement des factures'];
    public const COSTS = ['superpdp'=>'SuperPDP', 'hosting'=>'Hébergement', 'software'=>'Logiciels', 'marketing'=>'Acquisition', 'other'=>'Autres charges'];
    public function __construct(private EntityManagerInterface $em) {}
    public static function month(string $value): \DateTimeImmutable
    {
        if (!preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $value)) throw new \InvalidArgumentException('Mois invalide.');
        return new \DateTimeImmutable($value.'-01 00:00:00', new \DateTimeZone('Europe/Paris'));
    }

    public function build(string $from, string $to, int $entity = 0, string $activity = 'all'): array
    {
        $start = self::month($from); $end = self::month($to)->modify('+1 month');
        if ($end <= $start || $start->modify('+36 months') < $end || !isset(self::ACTIVITIES[$activity])) throw new \InvalidArgumentException('Choisissez une période de 1 à 36 mois et une activité valide.');
        $entities = $this->em->getRepository(Entite::class)->findBy([], ['nom'=>'ASC']);
        $roster = []; $subscriptions = []; $sources = [];
        foreach ($this->em->getRepository(EntiteSubscription::class)->findBy([], ['id'=>'ASC']) as $s) $subscriptions[$s->getEntite()->getId()] = $s;
        foreach ($this->em->getRepository(Referral::class)->findAll() as $r) $sources[$r->entite->getId()] = $r->partner->name.' · '.$r->source;
        foreach ($entities as $e) {
            $s = $subscriptions[$e->getId()] ?? null;
            $price = $s?->getPlan()?->getPriceFor($s->getIntervale());
            $roster[$e->getId()] = ['id'=>$e->getId(), 'name'=>$e->getNom(), 'created'=>$e->getDateCreation(),
                'location'=>trim(($e->getVille() ?? '').' · '.($e->getPays() ?? ''), ' ·'),
                'source'=>$sources[$e->getId()] ?? 'Origine non enregistrée', 'plan'=>$s?->getPlan()?->getName() ?? 'Sans offre',
                'status'=>$s?->getStatus() ?? 'Aucun', 'interval'=>$s?->getIntervale() ?? '',
                'renewal'=>$s?->getCurrentPeriodEnd(), 'accounts'=>0,'admins'=>0,'trainers'=>0,'learners'=>0,
                'mrr'=>$s?->getStatus() === 'active' && $price !== null ? (int)round($price / ($s->getIntervale() === 'year' ? 12 : 1)) : 0,
                'revenue'=>0];
        }
        if ($entity && !isset($roster[$entity])) throw new \InvalidArgumentException('Entité inconnue.');
        // One scalar query, without loading every user profile.
        $members = $this->em->createQuery('SELECT IDENTITY(m.entite) AS tenant, m.roles AS roles FROM App\Entity\UtilisateurEntite m')->getArrayResult();
        foreach ($members as $m) {
            if (!isset($roster[$m['tenant']])) continue;
            ++$roster[$m['tenant']]['accounts'];
            foreach (['TENANT_ADMIN'=>'admins','TENANT_FORMATEUR'=>'trainers','TENANT_STAGIAIRE'=>'learners'] as $role=>$key)
                if (in_array($role, $m['roles'], true)) ++$roster[$m['tenant']][$key];
        }
        $rows = []; $excluded = 0; $lastSync = null;
        $add = function(string $type, int $tenant, \DateTimeImmutable $date, string $label, ?int $ht, int $ttc, ?int $fees, string $state) use (&$rows, $roster, $activity, $entity): void {
            if (($entity && $tenant !== $entity) || ($activity !== 'all' && $activity !== $type)) return;
            $rows[] = ['activity'=>$type,'entity'=>$tenant,'name'=>$roster[$tenant]['name'] ?? 'Vendeur externe',
                'date'=>$date,'label'=>$label,'ht'=>$ht,'ttc'=>$ttc,'fees'=>$fees,'state'=>$state];
        };
        foreach ($this->range(Revenue::class, 'paidAt', $start, $end) as $r) {
            if (!$r->live || $r->currency !== 'eur') { ++$excluded; continue; }
            if (!$lastSync || $r->syncedAt > $lastSync) $lastSync = $r->syncedAt;
            $add('subscriptions', $r->entite->getId(), $r->paidAt, $r->label, $r->ht,
                max(0,$r->paid-$r->refund), $r->fee, $r->review ? 'À rapprocher' : ($r->refund ? 'Remboursé en partie ou totalité' : 'Payé'));
        }
        $volume = 0;
        foreach ($this->range(Purchase::class, 'paidAt', $start, $end) as $p) {
            $tenant = $p->seller?->user?->getEntite()?->getId() ?? 0;
            if ($entity && $tenant !== $entity) continue;
            if ($activity !== 'all' && $activity !== 'courses') continue;
            $volume += max(0,$p->totalCents-$p->refundedCents);
            if ($p->status === 'disputed') continue;
            $ratio = $p->totalCents > 0 ? max(0,1-$p->refundedCents/$p->totalCents) : 0;
            $ttc = $p->platformSale ? max(0,$p->totalCents-$p->refundedCents) : (int)round($p->commissionGrossCents()*$ratio);
            $ht = $p->refundedCents ? null : ($p->platformSale ? array_sum(array_column($p->items,'net')) : $p->commissionCents);
            $verified = ($p->consent['stripe_processing_fees']['status'] ?? '') === 'verified';
            $fees = $p->feesPayer === 'seller' && !$p->platformSale ? 0 : ($verified ? $p->stripeFeeCents : null);
            $add('courses', $tenant, $p->paidAt, ($p->platformSale ? 'Vente directe ' : 'Commission formation ').$p->id, $ht, $ttc, $fees,
                $p->refundedCents ? 'Net de remboursement · commission proratisée' : 'Payé');
        }
        $checkouts = [];
        foreach ($this->em->getRepository(FactureCheckout::class)->findBy(['status'=>'completed']) as $c)
            if ($c->getStripePaymentIntentId()) $checkouts[$c->getStripePaymentIntentId()] = $c;
        foreach ($this->range(Paiement::class, 'datePaiement', $start, $end) as $payment) {
            $c = $checkouts[$payment->getStripePaymentIntentId()] ?? null;
            if (!$c) continue;
            if (strtoupper($payment->getDevise()) !== 'EUR') { ++$excluded; continue; }
            $add('invoices', $c->getEntite()->getId(), $payment->getDatePaiement(),
                'Service paiement '.$c->getFacture()->getNumero(), null, $c->getServiceFeeCents(), null, 'Commission encaissée · TVA à rapprocher');
        }
        $usage = ['count'=>0,'sales'=>0,'provider'=>0,'reserve'=>0,'missing'=>0];
        if (in_array($activity,['all','subscriptions'],true)) foreach ($this->range(EInvoiceUsage::class,'acceptedAt',$start,$end) as $u) {
            if ($entity && $u->getConnection()->getEntite()->getId() !== $entity) continue;
            ++$usage['count']; $usage['sales'] += $u->getUnitPriceCents();
            $allocation = $u->getInternalAllocation();
            if (!$allocation) { ++$usage['missing']; continue; }
            $usage['provider'] += $allocation['providerCostCents'] ?? 0;
            $usage['reserve'] += $allocation['paymentProcessingReserveCents'] ?? 0;
        }
        $costs = []; $costHt=0; $costTtc=0; $estimates=0;
        foreach ($this->range(Cost::class,'date',$start,$end) as $c) {
            if ($c->archived || ($entity && $c->entite?->getId() !== $entity)) continue;
            if ($activity !== 'all' && $c->activity !== $activity) continue;
            $costs[]=$c;
            if ($c->estimated) $estimates += $c->ttc;
            else { $costHt += $c->ht; $costTtc += $c->ttc; }
        }
        $affiliate=0;
        if (in_array($activity,['all','subscriptions'],true)) foreach ($this->range(Commission::class,'paidAt',$start,$end) as $c) {
            if (($entity && $c->referral->entite->getId() !== $entity) || $c->currency !== 'eur' || in_array($c->status,['review','zero','canceled'],true)) continue;
            $affiliate += max(0,$c->netCents-$c->reversedCents);
        }
        $months=[]; for ($m=$start;$m<$end;$m=$m->modify('+1 month')) $months[$m->format('Y-m')]=['revenue'=>0,'fees'=>0,'costs'=>0,'count'=>0];
        $totals=['ht'=>0,'ttc'=>0,'fees'=>0,'missingHt'=>0,'missingFees'=>0,'count'=>count($rows)];
        $breakdown=[]; foreach (self::ACTIVITIES as $key=>$label) if ($key!=='all') $breakdown[$key]=['label'=>$label,'ht'=>0,'ttc'=>0,'count'=>0,'missing'=>0];
        foreach ($rows as $r) {
            $totals['ttc'] += $r['ttc']; $totals['ht'] += $r['ht'] ?? 0; $totals['fees'] += $r['fees'] ?? 0;
            $totals['missingHt'] += (int)($r['ht']===null); $totals['missingFees'] += (int)($r['fees']===null);
            $key=$r['date']->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m');
            $months[$key]['revenue'] += $r['ttc']; $months[$key]['fees'] += $r['fees'] ?? 0; ++$months[$key]['count'];
            $breakdown[$r['activity']]['ttc'] += $r['ttc']; $breakdown[$r['activity']]['ht'] += $r['ht'] ?? 0;
            ++$breakdown[$r['activity']]['count']; $breakdown[$r['activity']]['missing'] += (int)($r['ht']===null);
            if (isset($roster[$r['entity']])) $roster[$r['entity']]['revenue'] += $r['ttc'];
        }
        foreach ($costs as $cost) if (!$cost->estimated) $months[$cost->date->format('Y-m')]['costs'] += $cost->ttc;
        $invoiceHt=0; $invoiceTtc=0; $invoiceCount=0;
        if (in_array($activity,['all','invoices'],true)) foreach ($this->range(Facture::class,'dateEmission',$start,$end) as $f) {
            if (($entity && $f->getEntite()->getId()!==$entity) || strtoupper($f->getDevise())!=='EUR') continue;
            $invoiceHt += $f->getMontantHtCents(); $invoiceTtc += $f->getMontantTtcCents(); ++$invoiceCount;
        }
        if ($entity) $roster=[$entity=>$roster[$entity]];
        $new = array_values(array_filter($roster, fn($r)=>$r['created'] >= $start && $r['created'] < $end));
        usort($new,fn($a,$b)=>$b['created']<=>$a['created']);
        usort($rows,fn($a,$b)=>$b['date']<=>$a['date']);
        return compact('entities','roster','new','rows','months','totals','breakdown','costs','costHt','costTtc','estimates','affiliate','usage','volume','invoiceHt','invoiceTtc','invoiceCount','excluded','lastSync')
            + ['mrr'=>array_sum(array_column($roster,'mrr')), 'accounts'=>array_sum(array_column($roster,'accounts')),
                'contribution'=>$totals['ttc']-$totals['fees']-$costTtc-$affiliate];
    }
    private function range(string $class, string $field, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        return $this->em->createQueryBuilder()->select('r')->from($class,'r')
            ->where("r.$field >= :start AND r.$field < :end")->setParameter('start',$start)->setParameter('end',$end)->getQuery()->getResult();
    }
}
