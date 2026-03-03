<?php
// src/Service/Elearning/ElearningAccessService.php
namespace App\Service\Elearning;

use App\Entity\Utilisateur;
use App\Entity\Entite;
use App\Entity\Elearning\ElearningCourse;
use App\Entity\Elearning\ElearningEnrollment;
use Doctrine\ORM\EntityManagerInterface;

final class ElearningAccessService
{
  public function __construct(private EntityManagerInterface $em) {}

  public function getActiveEnrollment(Entite $entite, Utilisateur $user, ElearningCourse $course): ?ElearningEnrollment
  {
    $enroll = $this->em->getRepository(ElearningEnrollment::class)->findOneBy([
      'entite' => $entite,
      'stagiaire' => $user,
      'course' => $course,
    ]);

    if (!$enroll) return null;
    return $enroll->isActiveNow() ? $enroll : null;
  }
}
