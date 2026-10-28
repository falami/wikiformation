<?php
declare(strict_types=1);
namespace App\Exception;

/** Known capability gap, distinct from a broken payment or invoice operation. */
final class MarketplaceInvoiceUnavailable extends \DomainException {}
