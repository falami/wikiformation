<?php
declare(strict_types=1);
namespace App\Service\Depense;
use App\Entity\{Entite,ExpenseImportRule};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
final class ExpenseImportRules
{
    public function __construct(private EntityManagerInterface $em) {}
    public function apply(Entite $entite,array $proposals): array
    {
        $rules=$this->em->getRepository(ExpenseImportRule::class)->findBy(['entite'=>$entite,'enabled'=>true],['priority'=>'ASC','id'=>'ASC']);
        foreach ($proposals as &$proposal) {
            $label=self::normalize($proposal['libelle']);
            foreach ($rules as $rule) {
                if ($rule->category?->getEntite()?->getId() !== $entite->getId() || $rule->supplier?->getEntite()?->getId() !== $entite->getId()) continue;
                $needle=self::normalize($rule->keyword);
                if ($needle === '' || !str_contains($label,$needle)) continue;
                $proposal['categorieId']=$rule->category->getId();
                $proposal['fournisseurId']=$rule->supplier->getId();
                $proposal['tvaDeductible']=$rule->deductible;
                $proposal['tvaDeductiblePct']=$rule->percentage;
                $proposal['appliedRule']=$rule->keyword;
                break;
            }
        }
        return $proposals;
    }
    public static function normalize(string $text): string { return strtolower((string)(new AsciiSlugger('fr'))->slug($text,' ')); }
}
