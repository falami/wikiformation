<?php
// src/Service/Elearning/ElearningEnrollmentManager.php
namespace App\Service\Elearning;

use App\Entity\Entite;
use App\Entity\Utilisateur;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningEnrollment;
use App\Enum\EnrollmentStatus;
use Doctrine\ORM\EntityManagerInterface;

final class ElearningEnrollmentManager
{
  public function __construct(private EntityManagerInterface $em) {}

  public function assignCourse(
    Entite $entite,
    Utilisateur $stagiaire,
    ElearningCourse $course,
    Utilisateur $createur,
    ?\DateTimeImmutable $startsAt = null,
    ?\DateTimeImmutable $endsAt = null
  ): ElearningEnrollment {
    $repo = $this->em->getRepository(ElearningEnrollment::class);

    $existing = $repo->findOneBy(['entite' => $entite, 'stagiaire' => $stagiaire, 'course' => $course]);
    if ($existing) {
      // “réactive” proprement si besoin
      $existing->setStatus(EnrollmentStatus::ACTIVE);
      $existing->setStartsAt($startsAt);
      $existing->setEndsAt($endsAt);
      $this->em->flush();
      return $existing;
    }

    $enroll = (new ElearningEnrollment())
      ->setEntite($entite)
      ->setCreateur($createur)
      ->setStagiaire($stagiaire)
      ->setCourse($course)
      ->setStartsAt($startsAt)
      ->setEndsAt($endsAt);

    $this->em->persist($enroll);
    $this->em->flush();

    return $enroll;
  }
}
