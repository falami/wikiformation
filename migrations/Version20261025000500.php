<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261025000500 extends AbstractMigration
{
 public function getDescription(): string { return 'Opt-in rappel de début de session et registre anti-doublon'; }
 public function up(Schema $schema): void {
  $this->addSql('ALTER TABLE session ADD start_reminder_enabled TINYINT(1) DEFAULT 0 NOT NULL');
  $this->addSql('CREATE TABLE session_start_reminder_delivery (id VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
 }
 public function down(Schema $schema): void { $this->addSql('DROP TABLE session_start_reminder_delivery'); $this->addSql('ALTER TABLE session DROP start_reminder_enabled'); }
}
