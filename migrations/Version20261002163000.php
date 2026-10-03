<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002163000 extends AbstractMigration
{
    public function getDescription(): string { return 'Participants collectés par lien privé du dossier de formation, avant validation et import.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL ou MariaDB requis.');
        $this->addSql("CREATE TABLE workflow_participant (id INT AUTO_INCREMENT NOT NULL, workflow_id INT NOT NULL, inscription_id INT DEFAULT NULL, position INT NOT NULL, prenom VARCHAR(100) NOT NULL, nom VARCHAR(100) NOT NULL, email VARCHAR(180) DEFAULT NULL, date_naissance DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_WP_WORKFLOW (workflow_id), INDEX IDX_WP_INSCRIPTION (inscription_id), UNIQUE INDEX uniq_workflow_participant_position (workflow_id, position), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE workflow_participant ADD CONSTRAINT FK_WP_WORKFLOW FOREIGN KEY (workflow_id) REFERENCES training_workflow (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE workflow_participant ADD CONSTRAINT FK_WP_INSCRIPTION FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Les participants collectés doivent être conservés.'); }
}
