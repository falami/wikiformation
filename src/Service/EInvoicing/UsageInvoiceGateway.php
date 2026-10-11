<?php
declare(strict_types=1);
namespace App\Service\EInvoicing;

use App\Entity\EInvoiceUsageBatch;
interface UsageInvoiceGateway
{
    public function validate(EInvoiceUsageBatch $batch): void;
    public function create(EInvoiceUsageBatch $batch): string;
    public function find(EInvoiceUsageBatch $batch): ?string;
}
