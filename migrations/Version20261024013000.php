<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261024013000 extends AbstractMigration
{
    public function getDescription(): string {return 'Apporteurs : attribution, conditions historisées et commissions récurrentes Stripe';}
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE affiliate_referral (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, entite_id INT NOT NULL, author_id INT NOT NULL, source VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_BB8D2B4B9393F8FE (partner_id), UNIQUE INDEX UNIQ_BB8D2B4B9BEA957A (entite_id), INDEX IDX_BB8D2B4BF675F31B (author_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE affiliate_partner (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(40) NOT NULL, active TINYINT(1) NOT NULL, automatic_payouts TINYINT(1) NOT NULL, stripe_account_id VARCHAR(100) DEFAULT NULL, payouts_enabled TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_6CCCF52477153098 (code), UNIQUE INDEX UNIQ_6CCCF524E065F932 (stripe_account_id), UNIQUE INDEX UNIQ_6CCCF524A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE affiliate_commission (id INT AUTO_INCREMENT NOT NULL, referral_id INT NOT NULL, terms_id INT NOT NULL, invoice_id VARCHAR(100) NOT NULL, subscription_id VARCHAR(100) NOT NULL, charge_id VARCHAR(100) DEFAULT NULL, plan_name VARCHAR(100) NOT NULL, currency VARCHAR(3) NOT NULL, base_cents INT NOT NULL, gross_cents INT NOT NULL, fee_cents INT NOT NULL, net_cents INT NOT NULL, refunded_cents INT NOT NULL, reversed_cents INT NOT NULL, status VARCHAR(30) NOT NULL, issue VARCHAR(255) DEFAULT NULL, paid_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', available_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', transfer_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', payout_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', transfer_id VARCHAR(100) DEFAULT NULL, payout_id VARCHAR(100) DEFAULT NULL, arrival_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', evidence JSON NOT NULL, UNIQUE INDEX UNIQ_2DEA49A52989F1FD (invoice_id), UNIQUE INDEX UNIQ_2DEA49A5537048AF (transfer_id), UNIQUE INDEX UNIQ_2DEA49A5C6D61B7F (payout_id), INDEX IDX_2DEA49A53CCAA4B7 (referral_id), INDEX IDX_2DEA49A553742F27 (terms_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE affiliate_terms (id INT AUTO_INCREMENT NOT NULL, partner_id INT NOT NULL, author_id INT NOT NULL, rate_bps INT NOT NULL, fee_bps INT NOT NULL, fee_fixed_cents INT NOT NULL, effective_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3D0C3AAD9393F8FE (partner_id), INDEX IDX_3D0C3AADF675F31B (author_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE affiliate_referral ADD CONSTRAINT FK_BB8D2B4B9393F8FE FOREIGN KEY (partner_id) REFERENCES affiliate_partner (id)');
        $this->addSql('ALTER TABLE affiliate_referral ADD CONSTRAINT FK_BB8D2B4B9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
        $this->addSql('ALTER TABLE affiliate_referral ADD CONSTRAINT FK_BB8D2B4BF675F31B FOREIGN KEY (author_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE affiliate_partner ADD CONSTRAINT FK_6CCCF524A76ED395 FOREIGN KEY (user_id) REFERENCES utilisateur (id)');
        $this->addSql('ALTER TABLE affiliate_commission ADD CONSTRAINT FK_2DEA49A53CCAA4B7 FOREIGN KEY (referral_id) REFERENCES affiliate_referral (id)');
        $this->addSql('ALTER TABLE affiliate_commission ADD CONSTRAINT FK_2DEA49A553742F27 FOREIGN KEY (terms_id) REFERENCES affiliate_terms (id)');
        $this->addSql('ALTER TABLE affiliate_terms ADD CONSTRAINT FK_3D0C3AAD9393F8FE FOREIGN KEY (partner_id) REFERENCES affiliate_partner (id)');
        $this->addSql('ALTER TABLE affiliate_terms ADD CONSTRAINT FK_3D0C3AADF675F31B FOREIGN KEY (author_id) REFERENCES utilisateur (id)');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE affiliate_commission');
        $this->addSql('DROP TABLE affiliate_referral');
        $this->addSql('DROP TABLE affiliate_terms');
        $this->addSql('DROP TABLE affiliate_partner');
    }
}
