<?php

namespace App\Entity;

use App\Repository\SessionJourRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SessionJourRepository::class)]
#[ORM\Table(name: 'session_jour')]
#[ORM\UniqueConstraint(name: 'uniq_session_jour_slot', columns: ['session_id', 'date_debut', 'date_fin'])]
class SessionJour
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'jours')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Session $session = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Renseignez la date et l’heure de début du créneau.')]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Renseignez la date et l’heure de fin du créneau.')]
    #[Assert\Expression(
        "this.getDateFin() == null or this.getDateDebut() == null or this.getDateFin() > this.getDateDebut()",
        message: "L’heure de fin doit être après l’heure de début."
    )]
    private ?\DateTimeImmutable $dateFin = null;

    // Null conserve le calcul automatique, y compris pour les créneaux existants.
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(message: 'La pause ne peut pas être négative.')]
    private ?int $pauseMinutes = null;

    #[ORM\ManyToOne(inversedBy: 'sessionJours')]
    private ?Formateur $formateur = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\ManyToOne(inversedBy: 'sessionJourCreateurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Utilisateur $createur = null;

    #[ORM\ManyToOne(inversedBy: 'sessionJourEntites')]
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

    public function getDateDebut(): ?\DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(?\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(?\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getPauseMinutes(): ?int
    {
        return $this->pauseMinutes;
    }

    public function setPauseMinutes(?int $pauseMinutes): static
    {
        $this->pauseMinutes = $pauseMinutes;
        return $this;
    }

    public function getDureeBruteMinutes(): int
    {
        return array_sum($this->minutesParDate());
    }

    public function getPauseEffectiveMinutes(): int
    {
        return $this->getDureeBruteMinutes() - $this->getDureeFormationMinutes();
    }

    /**
     * Une journée complète traversant le déjeuner déduit 90 minutes en mode
     * automatique. Les heures supplémentaires restent comptées. Une demi-journée
     * conserve sa durée réelle : un déjeuner entre deux créneaux distincts n'est
     * pas déduit une seconde fois.
     * Une pause explicite (dont zéro) remplace cette règle pour le créneau.
     *
     * @return array<string, int> Minutes pédagogiques par date civile.
     */
    public function getDureeFormationMinutesParDate(): array
    {
        $minutes = $this->minutesParDate();
        if ($this->pauseMinutes === null) {
            foreach ($this->plagesParDate() as $date => [$start, $end]) {
                $lunch = $start->setTime(13, 0);
                if ($minutes[$date] >= 360 && $start < $lunch && $end > $lunch) {
                    $minutes[$date] -= 90;
                }
            }
            return $minutes;
        }
        // Les créneaux multi-dates historiques restent calculables. Répartir une
        // pause explicite au prorata conserve exactement le total demandé.
        $total = array_sum($minutes);
        $remainingPause = min($total, max(0, $this->pauseMinutes));
        $remainingMinutes = $total;
        foreach ($minutes as $date => $value) {
            $pause = $remainingMinutes > 0 ? (int) round($remainingPause * $value / $remainingMinutes) : 0;
            $minutes[$date] = max(0, $value - $pause);
            $remainingPause -= $pause;
            $remainingMinutes -= $value;
        }
        return $minutes;
    }

    public function getDureeFormationMinutes(): int
    {
        return array_sum($this->getDureeFormationMinutesParDate());
    }

    public function getDureeFormationHeures(): float
    {
        return round($this->getDureeFormationMinutes() / 60, 2);
    }

    #[Assert\Callback]
    public function validatePause(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->pauseMinutes !== null && $this->dateDebut && $this->dateFin && $this->dateFin > $this->dateDebut
            && $this->pauseMinutes >= $this->getDureeBruteMinutes()) {
            $context->buildViolation('La pause doit être plus courte que le créneau de formation.')
                ->atPath('pauseMinutes')->addViolation();
        }
    }

    /** @return array<string, int> */
    private function minutesParDate(): array
    {
        $minutes = [];
        foreach ($this->plagesParDate() as $date => [$start, $end]) {
            $minutes[$date] = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60);
        }
        return $minutes;
    }

    /** @return array<string, array{\DateTimeImmutable, \DateTimeImmutable}> */
    private function plagesParDate(): array
    {
        $start = $this->dateDebut;
        $end = $this->dateFin;
        if (!$start || !$end || $end <= $start) return [];
        $ranges = [];

        // L'ancien planning pouvait saisir une période entière dans une ligne.
        // Répéter les horaires de travail quotidiens exclut les nuits de la durée.
        if ($start->format('Y-m-d') !== $end->format('Y-m-d') && $end->format('H:i:s') > $start->format('H:i:s')) {
            $lastDate = $end->format('Y-m-d');
            while ($start->format('Y-m-d') <= $lastDate) {
                $ranges[$start->format('Y-m-d')] = [$start, $start->setTime((int) $end->format('H'), (int) $end->format('i'), (int) $end->format('s'))];
                $start = $start->modify('+1 day');
            }
            return $ranges;
        }

        // Un créneau de nuit (ex. 22:00–06:00) reste un intervalle continu.
        while ($start < $end) {
            $next = min($start->modify('tomorrow')->setTime(0, 0), $end);
            $ranges[$start->format('Y-m-d')] = [$start, $next];
            $start = $next;
        }
        return $ranges;
    }

    public function getFormateur(): ?Formateur
    {
        return $this->formateur;
    }

    public function setFormateur(?Formateur $formateur): static
    {
        $this->formateur = $formateur;

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
