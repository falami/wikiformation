<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261025023000 extends AbstractMigration {
 public function getDescription(): string { return 'Distinguer les ventes propres de la plateforme des ventes de formateurs'; }
 public function up(Schema $schema): void { $this->addSql('ALTER TABLE marketplace_purchase ADD platform_sale TINYINT(1) DEFAULT 0 NOT NULL'); }
 public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('La destination des ventes historiques doit être conservée.'); }
}
