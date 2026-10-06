<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class HabilitationTemplate
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    public function getId(): ?int { return $this->id; }

    #[ORM\ManyToOne(targetEntity: Entite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;
    public function getEntite(): ?Entite { return $this->entite; }
    public function setEntite(?Entite $value): self { $this->entite = $value; return $this; }

    #[ORM\Column(length: 180)]
    private string $titre = '';
    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $value): self { $this->titre = $value; return $this; }

    #[ORM\Column(name: 'schema_definition', type: 'json')]
    private array $schema = [];
    public function getSchema(): array { return $this->schema; }
    public function setSchema(array $value): self { $this->schema = $value; return $this; }

    #[ORM\Column]
    private bool $active = true;
    public function getActive(): bool { return $this->active; }
    public function setActive(bool $value): self { $this->active = $value; return $this; }

    #[ORM\Version, ORM\Column(type: 'integer')]
    private int $version = 1;
    public function getVersion(): int { return $this->version; }
}
