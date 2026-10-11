<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008193500 extends AbstractMigration
{
    public function getDescription(): string { return 'Registre qualité par organisme : réclamations, veille, actions et justificatifs'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE quality_record (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, created_by_id INT NOT NULL, updated_by_id INT NOT NULL, kind VARCHAR(30) NOT NULL, title VARCHAR(180) NOT NULL, description LONGTEXT NOT NULL, occurred_at DATE NOT NULL COMMENT '(DC2Type:date_immutable)', owner VARCHAR(180) NOT NULL, due_at DATE DEFAULT NULL COMMENT '(DC2Type:date_immutable)', actions LONGTEXT DEFAULT NULL, evidence LONGTEXT DEFAULT NULL, conclusion LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', version INT DEFAULT 1 NOT NULL, INDEX quality_entity_status (entite_id, status), INDEX quality_created_by (created_by_id), INDEX quality_updated_by (updated_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE quality_record ADD CONSTRAINT quality_entity_fk FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT, ADD CONSTRAINT quality_creator_fk FOREIGN KEY (created_by_id) REFERENCES utilisateur (id) ON DELETE RESTRICT, ADD CONSTRAINT quality_updater_fk FOREIGN KEY (updated_by_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE quality_record'); }
}
