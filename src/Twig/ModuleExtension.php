<?php
declare(strict_types=1);
namespace App\Twig;

use App\Service\Module\ModuleEntitlement;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ModuleExtension extends AbstractExtension
{
    public function __construct(private ModuleEntitlement $modules) {}
    public function getFunctions(): array
    {
        return [new TwigFunction('module_allowed', [$this->modules, 'allowed'])];
    }
}
