<?php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Dossiers automatisés par convention et journal durable des actions.'; }
    public function isTransactional(): bool { return false; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE training_workflow (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, session_id INT NOT NULL, convention_id INT NOT NULL, createur_id INT NOT NULL, facture_id INT DEFAULT NULL, contact_email VARCHAR(180) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, renewal_enabled TINYINT(1) NOT NULL DEFAULT 0, validity_months INT DEFAULT NULL, renewal_opt_out TINYINT(1) NOT NULL DEFAULT 0, portal_nonce VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', options JSON NOT NULL, INDEX IDX_TW_ENTITE (entite_id), INDEX IDX_TW_SESSION (session_id), UNIQUE INDEX UNIQ_TW_CONVENTION (convention_id), INDEX IDX_TW_CREATEUR (createur_id), INDEX IDX_TW_FACTURE (facture_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE training_workflow ADD CONSTRAINT FK_TW_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_workflow ADD CONSTRAINT FK_TW_SESSION FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_workflow ADD CONSTRAINT FK_TW_CONVENTION FOREIGN KEY (convention_id) REFERENCES convention_contrat (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE training_workflow ADD CONSTRAINT FK_TW_CREATEUR FOREIGN KEY (createur_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE training_workflow ADD CONSTRAINT FK_TW_FACTURE FOREIGN KEY (facture_id) REFERENCES facture (id) ON DELETE SET NULL');
        $this->addSql("CREATE TABLE workflow_task (id INT AUTO_INCREMENT NOT NULL, workflow_id INT NOT NULL, task_key VARCHAR(100) NOT NULL, action VARCHAR(40) NOT NULL, target_id INT DEFAULT NULL, due_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', status VARCHAR(20) NOT NULL, detail LONGTEXT DEFAULT NULL, processed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', snapshot JSON NOT NULL, INDEX IDX_WT_WORKFLOW (workflow_id), INDEX IDX_WT_DUE (status, due_at), UNIQUE INDEX uniq_workflow_task_key (workflow_id, task_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE workflow_task ADD CONSTRAINT FK_WT_WORKFLOW FOREIGN KEY (workflow_id) REFERENCES training_workflow (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Conserver le journal des opérations et des envois.'); }
}
