<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Accès QR individuels aux émargements et appréciations, y compris les participants nommés sans compte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE session_participant_access (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, entite_id INT NOT NULL, inscription_id INT DEFAULT NULL, convention_id INT DEFAULT NULL, public_id VARCHAR(48) NOT NULL, source_key VARCHAR(100) NOT NULL, display_name LONGTEXT NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, version INT DEFAULT 1 NOT NULL, expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_participant_public_id (public_id), UNIQUE INDEX uniq_participant_access_source (session_id, source_key), INDEX idx_participant_session (session_id), INDEX idx_participant_entite (entite_id), INDEX idx_participant_inscription (inscription_id), INDEX idx_participant_convention (convention_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE session_participant_access ADD CONSTRAINT fk_participant_session FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE, ADD CONSTRAINT fk_participant_entite FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE, ADD CONSTRAINT fk_participant_inscription FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON DELETE SET NULL, ADD CONSTRAINT fk_participant_convention FOREIGN KEY (convention_id) REFERENCES convention_contrat (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE emargement ADD participant_access_id INT DEFAULT NULL, MODIFY utilisateur_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE emargement ADD CONSTRAINT fk_emargement_participant FOREIGN KEY (participant_access_id) REFERENCES session_participant_access (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_emargement_participant ON emargement (participant_access_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_emargement_participant_date_periode ON emargement (session_id, participant_access_id, date_jour, periode)');
        $this->addSql('ALTER TABLE satisfaction_assignment ADD participant_access_id INT DEFAULT NULL, MODIFY stagiaire_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE satisfaction_assignment ADD CONSTRAINT fk_satisfaction_participant FOREIGN KEY (participant_access_id) REFERENCES session_participant_access (id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_satisfaction_participant ON satisfaction_assignment (participant_access_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sat_participant_template ON satisfaction_assignment (participant_access_id, template_id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les signatures et réponses des participants sans compte doivent être conservées.');
    }
}
