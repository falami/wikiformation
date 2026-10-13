<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261013220000 extends AbstractMigration
{
    public function getDescription(): string { return 'Profils formateurs : matières, diplômes privés et disponibilités'; }
    public function up(Schema $schema): void {
        $this->addSql("CREATE TABLE trainer_profile (id INT AUTO_INCREMENT NOT NULL, formateur_id INT NOT NULL, subjects JSON NOT NULL, working_days JSON NOT NULL, declared_available TINYINT(1) NOT NULL, unavailable JSON NOT NULL, calendar_secret LONGTEXT DEFAULT NULL, busy JSON NOT NULL, synced_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', attempted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', covered_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', sync_error VARCHAR(255) DEFAULT NULL, UNIQUE INDEX trainer_profile_owner (formateur_id), PRIMARY KEY(id), FOREIGN KEY (formateur_id) REFERENCES formateur(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE trainer_diploma (id INT AUTO_INCREMENT NOT NULL, formateur_id INT NOT NULL, title VARCHAR(180) NOT NULL, filename VARCHAR(100) NOT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(80) NOT NULL, uploaded_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX diploma_owner (formateur_id), PRIMARY KEY(id), FOREIGN KEY (formateur_id) REFERENCES formateur(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE trainer_diploma'); $this->addSql('DROP TABLE trainer_profile'); }
}
