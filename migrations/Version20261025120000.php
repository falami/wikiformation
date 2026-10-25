<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261025120000 extends AbstractMigration
{
 public function getDescription(): string { return 'Facturation marketplace sous mandat, TVA de commission et journal immuable ; ventes maintenues fermées pour validation.'; }
 public function up(Schema $schema): void {
  foreach (['country'=>"VARCHAR(2) NOT NULL DEFAULT ''",'postal_code'=>"VARCHAR(10) NOT NULL DEFAULT ''",'mandates'=>'JSON DEFAULT NULL'] as $column=>$definition) {
   if (!$schema->getTable('marketplace_seller')->hasColumn($column)) $this->addSql('ALTER TABLE marketplace_seller ADD '.$column.' '.$definition);
  }
  $this->addSql("UPDATE marketplace_seller SET mandates = JSON_ARRAY() WHERE mandates IS NULL");
  $this->addSql("ALTER TABLE marketplace_seller MODIFY mandates JSON NOT NULL");
  foreach (['billing_version','commission_tax_cents'] as $column) {
   if (!$schema->getTable('marketplace_purchase')->hasColumn($column)) $this->addSql('ALTER TABLE marketplace_purchase ADD '.$column.' INT DEFAULT 0 NOT NULL');
  }
  $parent=$this->connection->fetchAssociative("SELECT CHARACTER_SET_NAME AS charset,COLLATION_NAME AS collation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='marketplace_purchase' AND COLUMN_NAME='id'");
  if (!$parent || !preg_match('/^[a-zA-Z0-9_]+$/D',$parent['charset']) || !preg_match('/^[a-zA-Z0-9_]+$/D',$parent['collation'])) throw new \RuntimeException('Encodage de marketplace_purchase.id introuvable.');
  $encoding=' CHARACTER SET '.$parent['charset'].' COLLATE '.$parent['collation'];
  if (!$schema->hasTable('marketplace_invoice')) {
  $this->addSql("CREATE TABLE marketplace_invoice (id INT AUTO_INCREMENT NOT NULL, purchase_id VARCHAR(40)".$encoding." NOT NULL, event_key VARCHAR(80) NOT NULL, series VARCHAR(60) NOT NULL, sequence INT NOT NULL, number VARCHAR(100) NOT NULL, kind VARCHAR(24) NOT NULL, issued_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', document JSON NOT NULL, pdf MEDIUMTEXT NOT NULL, sha256 VARCHAR(64) NOT NULL, notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', buyer_notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', contested_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', contestation LONGTEXT NOT NULL, UNIQUE INDEX market_invoice_event (purchase_id,event_key), UNIQUE INDEX market_invoice_sequence (series,sequence), UNIQUE INDEX market_invoice_number (number), PRIMARY KEY(id), CONSTRAINT market_invoice_purchase FOREIGN KEY (purchase_id) REFERENCES marketplace_purchase (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE=InnoDB");
  }
  $this->addSql("UPDATE marketplace_settings SET commission_bps=2000, fees_payer='seller', enabled=0, legal_approved=0, terms_version='FR-2026-10-25-v1'");
 }
 public function isTransactional(): bool { return false; }
 public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Préserver les mandats et les documents comptables.'); }
}
