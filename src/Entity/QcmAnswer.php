<?php

namespace App\Entity;

use App\Repository\QcmAnswerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QcmAnswerRepository::class)]
class QcmAnswer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'answers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?QcmAttempt $attempt = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAnswers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?QcmQuestion $question = null;

    /**
     * @var Collection<int, QcmOption>
     */
    #[ORM\ManyToMany(targetEntity: QcmOption::class, inversedBy: 'qcmAnswers')]
    #[ORM\JoinTable(name: 'qcm_answer_option')]
    private Collection $selectedOptions;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAsnwerCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'qcmAnswerEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->selectedOptions = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAttempt(): ?QcmAttempt
    {
        return $this->attempt;
    }

    public function setAttempt(?QcmAttempt $attempt): static
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getQuestion(): ?QcmQuestion
    {
        return $this->question;
    }

    public function setQuestion(?QcmQuestion $question): static
    {
        $this->question = $question;

        return $this;
    }

    /**
     * @return Collection<int, QcmOption>
     */
    public function getSelectedOptions(): Collection
    {
        return $this->selectedOptions;
    }

    public function addSelectedOption(QcmOption $selectedOption): static
    {
        if (!$this->selectedOptions->contains($selectedOption)) {
            $this->selectedOptions->add($selectedOption);
        }

        return $this;
    }

    public function removeSelectedOption(QcmOption $selectedOption): static
    {
        $this->selectedOptions->removeElement($selectedOption);

        return $this;
    }


    public function setSelectedOptions(iterable $opts): static
    {
        $this->selectedOptions->clear();
        foreach ($opts as $o) {
            if ($o instanceof QcmOption) $this->selectedOptions->add($o);
        }
        return $this;
    }

    /** @return int[] */
    public function getSelectedOptionIds(): array
    {
        $ids = [];
        foreach ($this->selectedOptions as $o) if ($o->getId()) $ids[] = $o->getId();
        sort($ids);
        return $ids;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeImmutable $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getCreateur(): ?Utilisateur
    {
        return $this->createur;
    }

    public function setCreateur(?Utilisateur $createur): static
    {
        $this->createur = $createur;

        return $this;
    }

    public function getEntite(): ?Entite
    {
        return $this->entite;
    }

    public function setEntite(?Entite $entite): static
    {
        $this->entite = $entite;

        return $this;
    }
}
