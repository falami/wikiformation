<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Additive migration: existing QCM PRE/POST tables and assignments are not altered. */
final class Version20261101143000 extends AbstractMigration
{
    public function getDescription(): string { return 'Évaluations théorie et pratique indépendantes des QCM de niveau existants, avec modèles et dossiers figés.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE evaluation_template (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, title VARCHAR(180) NOT NULL, schema_definition JSON NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_EVAL_TEMPLATE_ENTITE (entite_id), INDEX IDX_EVAL_TEMPLATE_CREATEUR (createur_id), INDEX idx_evaluation_template_tenant (entite_id, active), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE evaluation_dossier (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, template_id INT NOT NULL, inscription_id INT NOT NULL, learner_id INT NOT NULL, training_session_id INT NOT NULL, evaluator_id INT NOT NULL, createur_id INT NOT NULL, previous_dossier_id INT DEFAULT NULL, snapshot JSON NOT NULL, theory_answers JSON NOT NULL, theory_grading JSON NOT NULL, practice_grading JSON NOT NULL, publication_record JSON NOT NULL, theory_verdict VARCHAR(16) DEFAULT NULL, practice_verdict VARCHAR(16) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', theory_submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', notification_sent_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', version INT DEFAULT 1 NOT NULL, INDEX IDX_EVAL_DOSSIER_ENTITE (entite_id), INDEX IDX_EVAL_DOSSIER_TEMPLATE (template_id), INDEX IDX_EVAL_DOSSIER_INSCRIPTION (inscription_id), INDEX IDX_EVAL_DOSSIER_LEARNER (learner_id), INDEX IDX_EVAL_DOSSIER_SESSION (training_session_id), INDEX IDX_EVAL_DOSSIER_EVALUATOR (evaluator_id), INDEX IDX_EVAL_DOSSIER_CREATEUR (createur_id), INDEX IDX_EVAL_DOSSIER_PREVIOUS (previous_dossier_id), INDEX idx_evaluation_dossier_worklist (entite_id, evaluator_id, published_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE evaluation_template ADD CONSTRAINT FK_EVAL_TEMPLATE_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_template ADD CONSTRAINT FK_EVAL_TEMPLATE_CREATEUR FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_TEMPLATE FOREIGN KEY (template_id) REFERENCES evaluation_template (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_INSCRIPTION FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_LEARNER FOREIGN KEY (learner_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_SESSION FOREIGN KEY (training_session_id) REFERENCES session (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_EVALUATOR FOREIGN KEY (evaluator_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_CREATEUR FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE evaluation_dossier ADD CONSTRAINT FK_EVAL_DOSSIER_PREVIOUS FOREIGN KEY (previous_dossier_id) REFERENCES evaluation_dossier (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_dossier DROP FOREIGN KEY FK_EVAL_DOSSIER_PREVIOUS');
        $this->addSql('DROP TABLE evaluation_dossier');
        $this->addSql('DROP TABLE evaluation_template');
    }
}
