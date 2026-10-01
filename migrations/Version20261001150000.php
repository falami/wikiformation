<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Bibliothèque de documents formateurs par organisme, fichiers privés et historique des versions.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');
        $this->addSql("CREATE TABLE document_formateur (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, titre VARCHAR(180) NOT NULL, description LONGTEXT DEFAULT NULL, categorie VARCHAR(30) NOT NULL, publie TINYINT(1) NOT NULL, updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', lock_version INT DEFAULT 1 NOT NULL, INDEX IDX_DF_ENTITE (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE document_formateur_version (id INT AUTO_INCREMENT NOT NULL, document_id INT NOT NULL, uploaded_by_id INT DEFAULT NULL, numero INT NOT NULL, filename VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(150) NOT NULL, size_bytes INT NOT NULL, note VARCHAR(500) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_DFV_DOCUMENT (document_id), INDEX IDX_DFV_USER (uploaded_by_id), UNIQUE INDEX uniq_document_formateur_numero (document_id, numero), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE document_formateur ADD CONSTRAINT FK_DF_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_formateur_version ADD CONSTRAINT FK_DFV_DOCUMENT FOREIGN KEY (document_id) REFERENCES document_formateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE document_formateur_version ADD CONSTRAINT FK_DFV_USER FOREIGN KEY (uploaded_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La suppression de la bibliothèque détruirait l’historique des documents.');
    }
}
