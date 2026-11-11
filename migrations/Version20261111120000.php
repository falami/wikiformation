<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261111120000 extends AbstractMigration
{
 public function getDescription(): string { return 'Independent CACES templates, sessions and candidate records'; }
 public function up(Schema $schema): void {
  $this->addSql('CREATE TABLE caces_template (id INT AUTO_INCREMENT NOT NULL, entite_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, recommendation VARCHAR(3) NOT NULL, category VARCHAR(12) NOT NULL, kind VARCHAR(12) NOT NULL, version_label VARCHAR(80) NOT NULL, status VARCHAR(16) NOT NULL, definition JSON NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
  $this->addSql('CREATE TABLE caces_exam (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, session_id INT NOT NULL, createur_id INT NOT NULL, theory_tester_id INT NOT NULL, practice_tester_id INT NOT NULL, recommendation VARCHAR(3) NOT NULL, category VARCHAR(12) NOT NULL, location VARCHAR(255) NOT NULL, theory_snapshot JSON NOT NULL, practice_snapshot JSON NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE RESTRICT, FOREIGN KEY (session_id) REFERENCES session(id) ON DELETE RESTRICT, FOREIGN KEY (createur_id) REFERENCES utilisateur(id) ON DELETE RESTRICT, FOREIGN KEY (theory_tester_id) REFERENCES utilisateur(id) ON DELETE RESTRICT, FOREIGN KEY (practice_tester_id) REFERENCES utilisateur(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
  $this->addSql('CREATE TABLE caces_attempt (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, exam_id INT NOT NULL, inscription_id INT NOT NULL, learner_id INT NOT NULL, answers JSON NOT NULL, theory_result JSON NOT NULL, practice_result JSON NOT NULL, profile JSON NOT NULL, signatures JSON NOT NULL, certificate JSON NOT NULL, started_at DATETIME DEFAULT NULL, submitted_at DATETIME DEFAULT NULL, practice_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE RESTRICT, FOREIGN KEY (exam_id) REFERENCES caces_exam(id) ON DELETE RESTRICT, FOREIGN KEY (inscription_id) REFERENCES inscription(id) ON DELETE RESTRICT, FOREIGN KEY (learner_id) REFERENCES utilisateur(id) ON DELETE RESTRICT, UNIQUE INDEX uniq_caces_exam_learner (exam_id, learner_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
 }
 public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve CACES examination records.'); }
}
