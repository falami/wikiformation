<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008202000 extends AbstractMigration
{
    public function getDescription(): string { return 'Émissions électroniques : preuves tarifaires par émission et lots Stripe mensuels durables, sans facturation des données historiques'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE einvoice_usage_batch (id INT AUTO_INCREMENT NOT NULL, connection_id INT NOT NULL, request_key VARCHAR(80) NOT NULL, period VARCHAR(7) NOT NULL, payload JSON NOT NULL, quantity INT NOT NULL, amount_cents INT NOT NULL, status VARCHAR(20) NOT NULL, attempted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', stripe_invoice_item_id VARCHAR(255) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, INDEX idx_einvoice_usage_batch_connection (connection_id), UNIQUE INDEX uniq_einvoice_usage_batch_key (request_key), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE einvoice_usage (id INT AUTO_INCREMENT NOT NULL, connection_id INT NOT NULL, transmission_id INT NOT NULL, batch_id INT DEFAULT NULL, provider_id VARCHAR(40) NOT NULL, unit_price_cents INT NOT NULL, stripe_customer_id VARCHAR(255) NOT NULL, stripe_subscription_id VARCHAR(255) NOT NULL, consented_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', accepted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', period VARCHAR(7) NOT NULL, INDEX idx_einvoice_usage_connection (connection_id), INDEX idx_einvoice_usage_batch (batch_id), UNIQUE INDEX uniq_einvoice_usage_transmission (transmission_id), UNIQUE INDEX uniq_einvoice_usage_provider (connection_id, provider_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE einvoice_usage_batch ADD CONSTRAINT fk_einvoice_usage_batch_connection FOREIGN KEY (connection_id) REFERENCES einvoice_connection (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE einvoice_usage ADD CONSTRAINT fk_einvoice_usage_connection FOREIGN KEY (connection_id) REFERENCES einvoice_connection (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_einvoice_usage_transmission FOREIGN KEY (transmission_id) REFERENCES einvoice_transmission (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_einvoice_usage_batch FOREIGN KEY (batch_id) REFERENCES einvoice_usage_batch (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Les preuves de consommation et de facturation ne doivent pas être supprimées automatiquement.'); }
}
