<?php

declare(strict_types=1);

namespace App\Service\Depense;

use App\Entity\{Entite, ExpenseImportRule};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ExpenseImportRules
{
    public function __construct(private EntityManagerInterface $em) {}

    public function apply(Entite $entite, array $proposals): array
    {
        $rules = $this->em->getRepository(ExpenseImportRule::class)->findBy(
            ['entite' => $entite, 'enabled' => true],
            ['priority' => 'ASC', 'id' => 'ASC'],
        );
        $matchingRules = [];
        foreach ($rules as $rule) {
            if ($rule->category?->getEntite()?->getId() !== $entite->getId()
                || $rule->supplier?->getEntite()?->getId() !== $entite->getId()
                || !is_finite($rule->percentage) || $rule->percentage < 0 || $rule->percentage > 100) {
                continue;
            }
            $needle = self::normalize($rule->keyword);
            if ($needle !== '') {
                $matchingRules[] = [$needle, $rule];
            }
        }
        foreach ($proposals as &$proposal) {
            $label = self::normalize($proposal['libelle']);
            foreach ($matchingRules as [$needle, $rule]) {
                if (!str_contains($label, $needle)) {
                    continue;
                }
                $proposal['categorieId'] = $rule->category->getId();
                $proposal['fournisseurId'] = $rule->supplier->getId();
                $proposal['tvaDeductible'] = $rule->deductible;
                $proposal['tvaDeductiblePct'] = $rule->deductible ? $rule->percentage : 0.0;
                $proposal['appliedRule'] = $rule->keyword;
                break;
            }
        }
        unset($proposal);

        return $proposals;
    }

    public static function normalize(string $text): string
    {
        return strtolower((string) (new AsciiSlugger('fr'))->slug($text, ' '));
    }
}
