<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Durable internal usage-only Stripe setup; no change to subscription rights.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE billing_internal_usage_setup (id VARCHAR(32) NOT NULL, entite_id INT NOT NULL, subscription_id INT NOT NULL, request JSON NOT NULL, acceptance JSON NOT NULL, customer_id VARCHAR(255) DEFAULT NULL, session_id VARCHAR(255) DEFAULT NULL, checkout_url VARCHAR(2048) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', attempted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_internal_usage_entite (entite_id), UNIQUE INDEX uniq_internal_usage_session (session_id), INDEX idx_internal_usage_subscription (subscription_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE billing_internal_usage_setup ADD CONSTRAINT fk_internal_usage_entite FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE billing_internal_usage_setup ADD CONSTRAINT fk_internal_usage_subscription FOREIGN KEY (subscription_id) REFERENCES billing_entite_subscription (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE billing_internal_usage_setup'); }
}
