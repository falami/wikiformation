<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Notification des nouveaux abonnements, sans relancer les abonnements déjà confirmés.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE billing_entite_subscription ADD subscription_notified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql("UPDATE billing_entite_subscription SET subscription_notified_at = CURRENT_TIMESTAMP WHERE stripe_subscription_id IS NOT NULL AND status <> 'incomplete'");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_entite_subscription DROP subscription_notified_at');
    }
}
