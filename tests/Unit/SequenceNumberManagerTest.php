<?php
namespace App\Tests\Unit;

use App\Service\Sequence\SequenceNumberManager;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SequenceNumberManagerTest extends TestCase
{
    public function testEverySequenceRequiresAndFollowsTheDocumentTransaction(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new SequenceNumberManager($db);
        foreach (['attestation', 'session', 'devis', 'avoir', 'contrat_formateur', 'convention_contrat', 'facture'] as $type) {
            $db->executeStatement("CREATE TABLE {$type}_sequence (entite_id INTEGER NOT NULL, year INTEGER NOT NULL, last INTEGER NOT NULL, UNIQUE(entite_id, year))");
            try {
                $manager->next($type, 1, 2026);
                self::fail('Allocation outside a document transaction must fail.');
            } catch (\LogicException) {
                self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM {$type}_sequence"));
            }
            // Failed first document: even creation of the counter is rolled back.
            $db->beginTransaction();
            self::assertSame([2026, 1], $manager->next($type, 1, 2026));
            $db->rollBack();
            $db->beginTransaction();
            self::assertSame([2026, 1], $manager->next($type, 1, 2026));
            self::assertSame([2026, 2], $manager->next($type, 1, 2026));
            self::assertSame([2026, 1], $manager->next($type, 2, 2026));
            self::assertSame([2027, 1], $manager->next($type, 1, 2027));
            $db->commit();
            $db->beginTransaction();
            self::assertSame([2026, 3], $manager->next($type, 1, 2026));
            self::assertTrue($db->isTransactionActive());
            $db->rollBack();
            $db->beginTransaction();
            self::assertSame([2026, 3], $manager->next($type, 1, 2026));
            $db->commit();
        }
    }
}
