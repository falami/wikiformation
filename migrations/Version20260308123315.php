<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260308123315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_entite_subscription ADD stripe_price_id VARCHAR(255) DEFAULT NULL, ADD started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD ended_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD cancel_at_period_end TINYINT(1) DEFAULT 0, ADD limits_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_plan CHANGE support_prioritaire support_prioritaire TINYINT(1) DEFAULT 0 NOT NULL, CHANGE is_active is_active TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_entite_subscription DROP stripe_price_id, DROP started_at, DROP ended_at, DROP cancel_at_period_end, DROP limits_snapshot');
        $this->addSql('ALTER TABLE billing_plan CHANGE support_prioritaire support_prioritaire TINYINT(1) NOT NULL, CHANGE is_active is_active TINYINT(1) NOT NULL');
    }
}
