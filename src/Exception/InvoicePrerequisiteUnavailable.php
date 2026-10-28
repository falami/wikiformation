<?php
declare(strict_types=1);
namespace App\Exception;

/** Issuance was refused by its prerequisites, before any electronic delivery or email. */
final class InvoicePrerequisiteUnavailable extends \DomainException {}
