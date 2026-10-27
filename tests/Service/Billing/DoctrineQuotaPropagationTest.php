<?php

declare(strict_types=1);

namespace App\Tests\Service\Billing;

use App\Exception\BillingQuotaExceededException;
use App\Service\Doctrine\DoctrineManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DoctrineQuotaPropagationTest extends TestCase
{
    public static function operations(): iterable
    {
        yield ['saveInDb', 'persist'];
        yield ['delete', 'remove'];
    }

    #[DataProvider('operations')]
    public function testQuotaRefusalReachesHttpResponseHandler(string $operation, string $ormOperation): void
    {
        $entity = new \stdClass();
        $error = new BillingQuotaExceededException('max_formateurs', 1, 1, 'Limite formateurs atteinte.');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method($ormOperation)->with($entity);
        $em->expects(self::once())->method('flush')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('critical');
        $this->expectExceptionObject($error);
        (new DoctrineManager($em, $logger))->$operation($entity, 'Database failure: ');
    }

    #[DataProvider('operations')]
    public function testUnexpectedDatabaseErrorsKeepExistingFailureBehavior(string $operation, string $ormOperation): void
    {
        $entity = new \stdClass();
        $error = new \RuntimeException('Unavailable: database-password secret');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method($ormOperation)->with($entity);
        $em->expects(self::once())->method('flush')->willThrowException($error);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('critical')->with(
            self::callback(static function (string $message) use ($error): bool {
                self::assertSame('Database failure', $message);
                self::assertStringNotContainsString($error->getMessage(), $message);
                self::assertStringNotContainsString('secret', $message);
                return true;
            }),
            ['exception' => $error],
        );
        self::assertFalse((new DoctrineManager($em, $logger))->$operation($entity, 'Database failure: '));
    }
}
