<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Un brouillon de facture unique par convention, sans modifier les factures existantes.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_brouillon ADD source_convention_id INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_draft_source_convention ON facture_brouillon (source_convention_id)');
        $this->addSql('ALTER TABLE facture_brouillon ADD CONSTRAINT FK_DRAFT_SOURCE_CONVENTION FOREIGN KEY (source_convention_id) REFERENCES convention_contrat (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facture_brouillon DROP FOREIGN KEY FK_DRAFT_SOURCE_CONVENTION');
        $this->addSql('DROP INDEX uniq_draft_source_convention ON facture_brouillon');
        $this->addSql('ALTER TABLE facture_brouillon DROP source_convention_id');
    }
}
