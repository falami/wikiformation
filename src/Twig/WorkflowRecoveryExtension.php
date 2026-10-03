<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Automation\WorkflowTaskRecovery;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class WorkflowRecoveryExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('workflow_recovery_revision', [WorkflowTaskRecovery::class, 'revision'])];
    }
}
