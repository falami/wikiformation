<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historique immuable des contrats formateurs : versions, PDF conservés et protection des modifications concurrentes.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');
        $this->addSql('ALTER TABLE contrat_formateur ADD version_numero INT DEFAULT 1 NOT NULL, ADD lock_version INT DEFAULT 1 NOT NULL');
        $this->addSql('CREATE TABLE contrat_formateur_revision (id INT AUTO_INCREMENT NOT NULL, contrat_id INT NOT NULL, auteur_id INT DEFAULT NULL, numero INT NOT NULL, donnees JSON NOT NULL, pdf_filename VARCHAR(255) NOT NULL, pdf_sha256 VARCHAR(64) NOT NULL, motif VARCHAR(1000) NOT NULL, auteur_nom VARCHAR(255) NOT NULL, archive_le DATETIME NOT NULL, INDEX IDX_CF_REV_CONTRAT (contrat_id), INDEX IDX_CF_REV_AUTEUR (auteur_id), UNIQUE INDEX uniq_contrat_revision (contrat_id, numero), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contrat_formateur_revision ADD CONSTRAINT FK_CF_REV_CONTRAT FOREIGN KEY (contrat_id) REFERENCES contrat_formateur (id) ON DELETE RESTRICT, ADD CONSTRAINT FK_CF_REV_AUTEUR FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les versions et les preuves de signature conservées ne doivent pas être supprimées.');
    }
}
