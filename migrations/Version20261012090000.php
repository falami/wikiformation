<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261012090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historique des dossiers et états de lecture individuels des notifications'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE notification_event (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, session_id INT DEFAULT NULL, subject_id INT DEFAULT NULL, company_id INT DEFAULT NULL, category VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL, actor VARCHAR(180) NOT NULL, occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', source_type VARCHAR(60) NOT NULL, source_id INT DEFAULT NULL, INDEX notification_tenant_date (entite_id, occurred_at), INDEX IDX_NE_SESSION (session_id), INDEX IDX_NE_SUBJECT (subject_id), INDEX IDX_NE_COMPANY (company_id), PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE, FOREIGN KEY (session_id) REFERENCES session(id) ON DELETE CASCADE, FOREIGN KEY (subject_id) REFERENCES utilisateur(id) ON DELETE CASCADE, FOREIGN KEY (company_id) REFERENCES entreprise(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE notification_receipt (entite_id INT NOT NULL, utilisateur_id INT NOT NULL, item_key VARCHAR(190) NOT NULL, read_at DATETIME DEFAULT NULL, archived TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY(entite_id, utilisateur_id, item_key), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE, FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE notification_receipt'); $this->addSql('DROP TABLE notification_event'); }
}
