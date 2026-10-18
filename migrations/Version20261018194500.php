<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261018194500 extends AbstractMigration
{
    public function getDescription(): string { return 'Recrutement national privé et fiches formateurs par entité.'; }
    public function up(Schema $schema): void
    {
        // Keep a leading index for the user foreign key before removing its former uniqueness.
        $this->addSql('CREATE INDEX IDX_RECRUIT_TRAINER_USER ON formateur (utilisateur_id)');
        foreach($schema->getTable('formateur')->getIndexes() as $index) {
            if($index->isUnique() && $index->getColumns()===['utilisateur_id']) $this->addSql('DROP INDEX '.$index->getName().' ON formateur');
        }
        $this->addSql('CREATE UNIQUE INDEX uniq_trainer_user_tenant ON formateur (utilisateur_id, entite_id)');
        $this->addSql('ALTER TABLE formateur ADD recruitment_details JSON DEFAULT NULL');
        $this->addSql("UPDATE formateur SET recruitment_details = '[]' WHERE recruitment_details IS NULL");
        $this->addSql('ALTER TABLE formateur MODIFY recruitment_details JSON NOT NULL');
        $this->addSql("CREATE TABLE trainer_application (id INT AUTO_INCREMENT NOT NULL, account_id INT DEFAULT NULL, email VARCHAR(180) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, phone VARCHAR(30) NOT NULL, details JSON NOT NULL, subjects JSON NOT NULL, departments JSON NOT NULL, nationwide TINYINT(1) NOT NULL, hourly_rate INT DEFAULT NULL, daily_rate INT DEFAULT NULL, cv_filename VARCHAR(100) NOT NULL, cv_original_name VARCHAR(255) NOT NULL, photo_filename VARCHAR(100) DEFAULT NULL, status VARCHAR(20) NOT NULL, notes LONGTEXT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', consented_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', version INT DEFAULT 1 NOT NULL, INDEX IDX_APPLICATION_ACCOUNT (account_id), INDEX IDX_APPLICATION_STATUS (status, created_at), PRIMARY KEY(id), CONSTRAINT FK_APPLICATION_ACCOUNT FOREIGN KEY(account_id) REFERENCES utilisateur(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE recruitment_placement (id INT AUTO_INCREMENT NOT NULL, application_id INT NOT NULL, entite_id INT NOT NULL, formateur_id INT NOT NULL, actor_id INT NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PLACEMENT_TENANT (entite_id), INDEX IDX_PLACEMENT_TRAINER (formateur_id), INDEX IDX_PLACEMENT_ACTOR (actor_id), UNIQUE INDEX uniq_recruitment_placement (application_id, entite_id), PRIMARY KEY(id), CONSTRAINT FK_PLACEMENT_APPLICATION FOREIGN KEY(application_id) REFERENCES trainer_application(id) ON DELETE CASCADE, CONSTRAINT FK_PLACEMENT_TENANT FOREIGN KEY(entite_id) REFERENCES entite(id) ON DELETE RESTRICT, CONSTRAINT FK_PLACEMENT_TRAINER FOREIGN KEY(formateur_id) REFERENCES formateur(id) ON DELETE RESTRICT, CONSTRAINT FK_PLACEMENT_ACTOR FOREIGN KEY(actor_id) REFERENCES utilisateur(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Les affectations multi-entités et les candidatures doivent être conservées.'); }
}
