<?php
declare(strict_types=1);
namespace App\Service\Module;
use App\Entity\Billing\{Module, ModuleAccess};
use App\Entity\Entite;
use App\Service\EInvoicing\EInvoiceTariff;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleEntitlement
{
    public function __construct(private EntityManagerInterface $em) {}
    public function internal(Entite $entite): bool { return EInvoiceTariff::isInternal($entite); }
    public function access(Entite $entite, Module $module): ?ModuleAccess
    {
        return $this->em->getRepository(ModuleAccess::class)->findOneBy(['entite' => $entite, 'module' => $module]);
    }
    public function allowed(Entite $entite, string $code): bool
    {
        if ($this->internal($entite)) return true;
        $module = $this->em->find(Module::class, $code);
        return $module !== null && ($module->included || $this->access($entite, $module)?->hasAccess());
    }
}
