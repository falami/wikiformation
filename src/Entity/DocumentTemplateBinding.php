<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'studio_tenant_kind', columns: ['entite_id', 'kind'])]
class DocumentTemplateBinding
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false, onDelete:'CASCADE')] private Entite $entite;
    #[ORM\Column(length:60)] private string $kind;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable:false, onDelete:'CASCADE')] private DocumentTemplate $template;
    #[ORM\Column(type:'json')] private array $config;
    #[ORM\Column] private \DateTimeImmutable $publishedAt;
    public function __construct(DocumentTemplate $template) { $this->entite=$template->getEntite(); $this->kind=$template->getKind(); $this->publish($template); }
    public function publish(DocumentTemplate $template): void {
        if ($template->getEntite() !== $this->entite || $template->getKind() !== $this->kind) throw new \LogicException('Affectation incompatible.');
        $this->template=$template; $this->config=$template->getConfig(); $this->publishedAt=new \DateTimeImmutable();
    }
    public function getTemplate(): DocumentTemplate { return $this->template; }
    public function getConfig(): array { return $this->config; }
    public function getKind(): string { return $this->kind; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
}
