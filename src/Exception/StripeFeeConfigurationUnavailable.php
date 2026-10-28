<?php

declare(strict_types=1);

namespace App\Exception;

/** Only safe, local messages may be displayed to the billing administrator. */
final class StripeFeeConfigurationUnavailable extends \RuntimeException
{
}
