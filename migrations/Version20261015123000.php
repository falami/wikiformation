<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015123000 extends AbstractMigration
{
    public function getDescription(): string { return 'Chartes qualité versionnées et signatures formateurs avec exemplaires PDF conservés'; }
    public function up(Schema $schema): void {
        $this->addSql("CREATE TABLE trainer_charter (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, title VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_CHARTER_ENTITE (entite_id), PRIMARY KEY(id), FOREIGN KEY(entite_id) REFERENCES entite(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE trainer_charter_version (id INT AUTO_INCREMENT NOT NULL, charter_id INT NOT NULL, number INT NOT NULL, revision INT DEFAULT 1 NOT NULL, title VARCHAR(180) NOT NULL, chapters JSON NOT NULL, issuer JSON NOT NULL, change_note VARCHAR(1000) NOT NULL, effective_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', digest VARCHAR(64) DEFAULT NULL, author_name VARCHAR(255) NOT NULL, UNIQUE INDEX uniq_charter_version (charter_id, number), PRIMARY KEY(id), FOREIGN KEY(charter_id) REFERENCES trainer_charter(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE trainer_charter_signature (id INT AUTO_INCREMENT NOT NULL, version_id INT NOT NULL, trainer_id INT NOT NULL, requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', signed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', requested_by VARCHAR(255) NOT NULL, evidence JSON NOT NULL, signed_pdf LONGBLOB DEFAULT NULL, pdf_digest VARCHAR(64) DEFAULT NULL, revision INT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_charter_signer (version_id, trainer_id), INDEX IDX_CHARTER_TRAINER (trainer_id), PRIMARY KEY(id), FOREIGN KEY(version_id) REFERENCES trainer_charter_version(id) ON DELETE RESTRICT, FOREIGN KEY(trainer_id) REFERENCES formateur(id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE trainer_charter_signature');$this->addSql('DROP TABLE trainer_charter_version');$this->addSql('DROP TABLE trainer_charter'); }
}
