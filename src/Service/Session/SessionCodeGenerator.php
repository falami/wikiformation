<?php

namespace App\Service\Session;

use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;

final class SessionCodeGenerator
{
  public function __construct(private EntityManagerInterface $em) {}

  public function generate(Session $session): string
  {
    // Exemples de morceaux : SK / site / année+semaine / suffix
    $formation = $session->getFormation();
    $site      = $session->getSite();

    // Préfixe "SK" (ou autre logique métier)
    $prefix = 'SK';

    // Code "site" : ville (3 lettres) ou nom
    $city = $site?->getVille() ?: $site?->getNom() ?: 'SITE';
    $city = $this->slugPart($city, 3); // MRS, PAR, etc.

    // Date de référence = dateDebut (si dispo) sinon now
    $d = $session->getDateDebut() ?? new \DateTimeImmutable('now');
    $year = $d->format('Y');
    $week = $d->format('W'); // ISO week

    // Suffixe incrémental pour éviter collisions (A, B, C...) ou 01,02...
    // Ici on fait 01..99 en fonction des codes déjà existants cette semaine
    $base = sprintf('%s-%s-%sW%s', $prefix, $city, $year, $week);

    $n = 1;
    do {
      $code = sprintf('%s-%02d', $base, $n);
      $exists = (bool) $this->em->getRepository(Session::class)->createQueryBuilder('s')
        ->select('1')
        ->andWhere('s.code = :c')->setParameter('c', $code)
        ->setMaxResults(1)
        ->getQuery()
        ->getOneOrNullResult();
      $n++;
    } while ($exists && $n < 100);

    return $code;
  }

  private function slugPart(string $str, int $len): string
  {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
    $s = strtoupper(preg_replace('~[^A-Z0-9]+~', '', $s ?? $str));
    $s = $s ?: 'XXX';
    return substr($s, 0, $len);
  }
}
