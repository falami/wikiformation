<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015023000 extends AbstractMigration
{
 public function getDescription(): string { return 'CV professionnels éditables, propositions IA et publication contrôlée'; }
 public function up(Schema $schema): void { $this->addSql("CREATE TABLE trainer_cv (id INT AUTO_INCREMENT NOT NULL, formateur_id INT NOT NULL, content JSON NOT NULL, published JSON DEFAULT NULL, suggestion JSON DEFAULT NULL, filename VARCHAR(100) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_TRAINER_CV (formateur_id), PRIMARY KEY(id), FOREIGN KEY (formateur_id) REFERENCES formateur(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB"); }
 public function down(Schema $schema): void { $this->addSql('DROP TABLE trainer_cv'); }
}
