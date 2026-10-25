<?php
declare(strict_types=1);
namespace App\Entity\Marketplace;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'marketplace_invoice')]
#[ORM\UniqueConstraint(name: 'market_invoice_event', columns: ['purchase_id','event_key'])]
#[ORM\UniqueConstraint(name: 'market_invoice_sequence', columns: ['series','sequence'])]
#[ORM\HasLifecycleCallbacks]
class Invoice
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] public ?int $id=null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] public ?Purchase $purchase=null;
    #[ORM\Column(length:80)] public string $eventKey='';
    #[ORM\Column(length:60)] public string $series='';
    #[ORM\Column] public int $sequence=0;
    #[ORM\Column(length:100,unique:true)] public string $number='';
    #[ORM\Column(length:24)] public string $kind='sale';
    #[ORM\Column] public ?\DateTimeImmutable $issuedAt=null;
    #[ORM\Column(type:'json')] public array $document=[];
    #[ORM\Column(type:'text',length:16777215)] public string $pdf='';
    #[ORM\Column(length:64)] public string $sha256='';
    #[ORM\Column(nullable:true)] public ?\DateTimeImmutable $notifiedAt=null;
    #[ORM\Column(nullable:true)] public ?\DateTimeImmutable $buyerNotifiedAt=null;
    #[ORM\Column(nullable:true)] public ?\DateTimeImmutable $contestedAt=null;
    #[ORM\Column(type:'text')] public string $contestation='';
    #[ORM\PreUpdate]
    public function immutable(\Doctrine\ORM\Event\PreUpdateEventArgs $event): void
    {
        foreach (array_keys($event->getEntityChangeSet()) as $field) {
            if (!in_array($field,['notifiedAt','buyerNotifiedAt','contestedAt','contestation'],true)) throw new \LogicException('Une facture émise est immuable. Émettez un avoir.');
        }
    }
    #[ORM\PreRemove]
    public function retain(): void { throw new \LogicException('Les factures émises doivent être conservées.'); }
}
