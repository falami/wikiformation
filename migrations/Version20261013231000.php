<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261013231000 extends AbstractMigration {
 public function getDescription():string{return 'Demandes de disponibilité et historique des échanges formateurs';}
 public function up(Schema $schema):void{$this->addSql("CREATE TABLE trainer_inquiry (id INT AUTO_INCREMENT NOT NULL, formateur_id INT NOT NULL, author_id INT NOT NULL, subject VARCHAR(100) NOT NULL, recipient VARCHAR(180) NOT NULL, slots JSON NOT NULL, message LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, request_key VARCHAR(64) NOT NULL, history JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX inquiry_request (request_key), INDEX inquiry_trainer (formateur_id), INDEX inquiry_author (author_id), PRIMARY KEY(id), FOREIGN KEY(formateur_id) REFERENCES formateur(id), FOREIGN KEY(author_id) REFERENCES utilisateur(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");}
 public function down(Schema $schema):void{$this->addSql('DROP TABLE trainer_inquiry');}
}
