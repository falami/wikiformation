<?php

namespace App\Tenant;

use App\Entity\Entite;
use App\Repository\EntiteRepository;
use Symfony\Component\HttpFoundation\Request;

final class TenantResolver
{
  public function __construct(
    private EntiteRepository $entiteRepo,
    private string $baseDomain,
  ) {}

  public function resolve(Request $request): ?Entite
  {
    $host = strtolower($request->getHost()); // ex: monof.wikiformation.fr
    $base = strtolower($this->baseDomain);

    if ($host === $base) return null;
    $suffix = '.' . $base;
    if (!str_ends_with($host, $suffix)) return null;

    $sub = substr($host, 0, -strlen($suffix)); // "monof"
    if ($sub === '' || $sub === 'www') return null;

    return $this->entiteRepo->findOneBy(['slug' => $sub, 'isActive' => true]);
  }
}
