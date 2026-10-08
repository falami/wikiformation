<?php

declare (strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261007170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attribution explicite des dossiers aux comptes commerciaux et OPCO';
    }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dossier_delegation (id INT AUTO_INCREMENT NOT NULL, membership_id INT NOT NULL, assigned_by_id INT DEFAULT NULL, module VARCHAR(30) NOT NULL, record_id INT NOT NULL, access_level VARCHAR(12) NOT NULL, assigned_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_DELEGATION_MEMBERSHIP (membership_id), INDEX IDX_DELEGATION_ACTOR (assigned_by_id), UNIQUE INDEX uniq_dossier_delegation (membership_id, module, record_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE dossier_delegation ADD CONSTRAINT FK_DELEGATION_MEMBERSHIP FOREIGN KEY (membership_id) REFERENCES utilisateur_entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dossier_delegation ADD CONSTRAINT FK_DELEGATION_ACTOR FOREIGN KEY (assigned_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE dossier_delegation');
    }
}
