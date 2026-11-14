<?php
declare(strict_types=1);
namespace App\Security\Attribute;

/** Apply to every controller exposing a paid module, including files and AJAX endpoints. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RequiresModule
{
    public function __construct(public readonly string $code) {}
}
