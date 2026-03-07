<?php

declare(strict_types=1);

namespace App\Service\Public;

use App\Repository\PublicHostRepository;

final class PublicHostResolver
{
    public function __construct(
        private readonly PublicHostRepository $publicHostRepository,
    ) {
    }

    public function resolve(string $host): ?\App\Entity\PublicHost
    {
        return $this->publicHostRepository->findActiveByHost($host);
    }
}