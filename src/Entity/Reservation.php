<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use App\Enum\StatusReservation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(name: 'idx_resa_status', columns: ['status'])]
#[ORM\Index(name: 'idx_resa_date', columns: ['date_reservation'])]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Utilisateur $utilisateur = null;

    #[ORM\Column(enumType: StatusReservation::class)]
    private StatusReservation $status = StatusReservation::PENDING;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $dateReservation = null;

    #[ORM\Column(options: ['unsigned' => true])]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private int $montantCents = 0;

    #[ORM\Column(length: 3, options: ['default' => 'EUR'])]
    private string $devise = 'EUR';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $paymentIntentId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $documents = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Formation $formation = null;

    #[ORM\Column(options: ['unsigned' => true])]
    #[Assert\NotNull]
    #[Assert\Positive]
    private int $places = 1;


    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateSouhaitee = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'reservationCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'reservationEntites')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Entite $entite = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): static
    {
        $this->session = $session;

        return $this;
    }

    public function getUtilisateur(): ?Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(?Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

        return $this;
    }

    public function getDateReservation(): ?\DateTimeImmutable
    {
        return $this->dateReservation;
    }

    public function setDateReservation(\DateTimeImmutable $dateReservation): static
    {
        $this->dateReservation = $dateReservation;

        return $this;
    }

    public function getMontantCents(): int
    {
        return $this->montantCents;
    }

    public function setMontantCents(int $montantCents): static
    {
        $this->montantCents = $montantCents;

        return $this;
    }

    public function getMontantEuros(): float
    {
        return $this->montantCents / 100;
    }

    public function getDevise(): string
    {
        return $this->devise;
    }

    public function setDevise(string $devise): static
    {
        $this->devise = $devise;

        return $this;
    }

    public function getPaymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function setPaymentIntentId(?string $paymentIntentId): static
    {
        $this->paymentIntentId = $paymentIntentId;

        return $this;
    }

    public function getDocuments(): ?array
    {
        return $this->documents;
    }

    public function setDocuments(?array $documents): static
    {
        $this->documents = $documents;

        return $this;
    }

    public function getStatusReservation(): StatusReservation
    {
        return $this->status;
    }

    public function setStatusReservation(StatusReservation $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): StatusReservation
    {
        return $this->status;
    }

    public function setStatus(StatusReservation $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getFormation(): ?Formation
    {
        return $this->formation;
    }

    public function setFormation(?Formation $formation): static
    {
        $this->formation = $formation;

        return $this;
    }

    public function getPlaces(): int
    {
        return $this->places;
    }
    public function setPlaces(int $places): static
    {
        $this->places = $places;
        return $this;
    }


    public function getDateSouhaitee(): ?\DateTimeImmutable
    {
        return $this->dateSouhaitee;
    }

    public function setDateSouhaitee(?\DateTimeImmutable $dateSouhaitee): static
    {
        $this->dateSouhaitee = $dateSouhaitee;

        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;

        return $this;
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
