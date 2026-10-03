<?php

declare(strict_types=1);

namespace App\Service\Session;

use App\Entity\Session;
use App\Repository\ConventionContratRepository;

final class SessionConventionTotals
{
    public function __construct(private readonly ConventionContratRepository $conventions) {}

    /** @return array{count: int, amounts: array<string, int>, estimatedCount: int, sharedQuoteCount: int, missingCount: int} */
    public function calculate(Session $session): array
    {
        $conventions = $this->conventions->createQueryBuilder('c')
            ->leftJoin('c.devis', 'd')->addSelect('d')
            ->andWhere('c.session = :session AND c.entite = :entite')
            ->setParameter('session', $session)->setParameter('entite', $session->getEntite())
            ->getQuery()->getResult();
        $result = ['count' => count($conventions), 'amounts' => [], 'estimatedCount' => 0, 'sharedQuoteCount' => 0, 'missingCount' => 0];
        $quotes = [];
        foreach ($conventions as $convention) {
            $quote = $convention->getDevis();
            if ($quote) {
                if ($quote->getEntite()?->getId() !== $session->getEntite()?->getId()) {
                    ++$result['missingCount'];
                    continue;
                }
                // A quote's full total is shared by its conventions, never multiplied.
                if (isset($quotes[$quote->getId()])) {
                    ++$result['sharedQuoteCount'];
                    continue;
                }
                $quotes[$quote->getId()] = true;
                $currency = $quote->getDevise();
                $amount = $quote->getMontantTtcCents();
            } else {
                $currency = 'EUR';
                $amount = $session->getTarifEffectifCents() * $convention->getEffectifTotal();
                ++$result['estimatedCount'];
            }
            $result['amounts'][$currency] = ($result['amounts'][$currency] ?? 0) + $amount;
        }
        if (!$conventions) {
            $result['amounts']['EUR'] = 0;
        }
        ksort($result['amounts']);

        return $result;
    }
}
