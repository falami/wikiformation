<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Expense import replay protection and exact receipt amounts'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense ADD import_key VARCHAR(64) DEFAULT NULL, ADD amounts_manual TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_DEPENSE_IMPORT_KEY ON depense (import_key)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_DEPENSE_IMPORT_KEY ON depense');
        $this->addSql('ALTER TABLE depense DROP import_key, DROP amounts_manual');
    }
}
