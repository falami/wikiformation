<?php
namespace App\Tests\Unit;

use App\Service\Sequence\SequenceNumberManager;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class SequenceNumberManagerTest extends TestCase
{
    public function testSequencesAreIsolatedAndFollowTransactionRollback(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->setNestTransactionsWithSavepoints(true);
        foreach (['attestation','session','devis','avoir','contrat_formateur','convention_contrat','facture'] as $type) {
            $db->executeStatement("CREATE TABLE {$type}_sequence (entite_id INTEGER NOT NULL, year INTEGER NOT NULL, last INTEGER NOT NULL, UNIQUE(entite_id, year))");
        }
        $manager = new SequenceNumberManager($db);
        foreach (['attestation','session','devis','avoir','contrat_formateur','convention_contrat'] as $type) {
            self::assertSame([2026,1],$manager->next($type,1,2026));
            self::assertSame([2026,2],$manager->next($type,1,2026));
            self::assertSame([2026,1],$manager->next($type,2,2026));
            self::assertSame([2027,1],$manager->next($type,1,2027));
        }
        $db->beginTransaction();
        self::assertSame([2026,3],$manager->next('attestation',1,2026));
        self::assertTrue($db->isTransactionActive());
        $db->rollBack();
        self::assertSame([2026,3],$manager->next('attestation',1,2026));
        $this->expectException(\LogicException::class);
        $manager->next('facture',1,2026);
    }
}
