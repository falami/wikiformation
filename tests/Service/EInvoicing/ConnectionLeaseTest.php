<?php
declare(strict_types=1);
namespace App\Tests\Service\EInvoicing;
use App\Service\EInvoicing\ConnectionLease;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionLeaseTest extends TestCase
{
    public function testMysqlNoOpRenewalKeepsOwnership(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())->method('executeStatement')->willReturn(0);
        $db->expects(self::once())->method('fetchOne')->with(self::stringContains('worker_token = ? AND worker_until >='), self::callback(static fn($args) => $args[0] === 12 && $args[1] === 'owner'))->willReturn(12);
        (new ConnectionLease($db))->renew(12, 'owner');
    }
    public function testExpiredOrReassignedLeaseCannotAuthorizeAnExternalSideEffect(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('executeStatement')->willReturn(0); $db->method('fetchOne')->willReturn(false);
        $this->expectException(\RuntimeException::class); (new ConnectionLease($db))->renew(12, 'old-owner');
    }
}
