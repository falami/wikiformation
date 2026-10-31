<?php
namespace App\Tests\Unit;
use App\Controller\Administrateur\FactureController;
use PHPUnit\Framework\TestCase;
final class InvoiceHtSummaryTest extends TestCase
{
    public function testPaidHtAllocationHandlesTaxesDisbursementsAndOverpayments(): void
    {
        $db = new \PDO('sqlite::memory:');
        $db->sqliteCreateFunction('LEAST', min(...));
        $db->sqliteCreateFunction('GREATEST', max(...));
        $controller = (new \ReflectionClass(FactureController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'paidHtExpression');
        foreach ([[10000,12000,6000,5000], [10000,10000,5000,5000], [10000,14000,7000,5000], [10000,12000,15000,10000], [10000,12000,0,0], [0,0,0,0], [10001,12001,6000,5000]] as [$ht,$ttc,$paid,$expected]) {
            $sql = $method->invoke($controller, (string)$ht, (string)$ttc, (string)$paid);
            self::assertSame($expected, (int)$db->query('SELECT '.$sql)->fetchColumn());
            self::assertSame($ht-$expected, (int)$db->query('SELECT '.$ht.' - ('.$sql.')')->fetchColumn());
        }
    }
}
