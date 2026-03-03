<?php
// src/Repository/SessionPieceRepository.php

namespace App\Repository;

use App\Entity\SessionPiece;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class SessionPieceRepository extends ServiceEntityRepository
{
  public function __construct(ManagerRegistry $registry)
  {
    parent::__construct($registry, SessionPiece::class);
  }
}
