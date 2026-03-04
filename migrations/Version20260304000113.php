<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260304000113 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE billing_entite_connect (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, stripe_account_id VARCHAR(255) DEFAULT NULL, details_submitted TINYINT(1) DEFAULT 0 NOT NULL, charges_enabled TINYINT(1) DEFAULT 0 NOT NULL, payouts_enabled TINYINT(1) DEFAULT 0 NOT NULL, online_payment_enabled TINYINT(1) DEFAULT 1 NOT NULL, fee_fixed_cents INT DEFAULT 0 NOT NULL, fee_percent_bp INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_entite_connect (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE billing_facture_checkout (id INT AUTO_INCREMENT NOT NULL, facture_id INT NOT NULL, entite_id INT NOT NULL, payeur_utilisateur_id INT DEFAULT NULL, payeur_entreprise_id INT DEFAULT NULL, stripe_checkout_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, status VARCHAR(30) NOT NULL, amount_total_cents INT NOT NULL, service_fee_cents INT NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3E412B9B7F2DEE08 (facture_id), INDEX IDX_3E412B9B9BEA957A (entite_id), INDEX IDX_3E412B9BBEE8EAEB (payeur_utilisateur_id), INDEX IDX_3E412B9B1BF97EC7 (payeur_entreprise_id), INDEX idx_checkout_session (stripe_checkout_session_id), INDEX idx_checkout_intent (stripe_payment_intent_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE billing_entite_connect ADD CONSTRAINT FK_253342EE9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_facture_checkout ADD CONSTRAINT FK_3E412B9B7F2DEE08 FOREIGN KEY (facture_id) REFERENCES facture (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_facture_checkout ADD CONSTRAINT FK_3E412B9B9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_facture_checkout ADD CONSTRAINT FK_3E412B9BBEE8EAEB FOREIGN KEY (payeur_utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE billing_facture_checkout ADD CONSTRAINT FK_3E412B9B1BF97EC7 FOREIGN KEY (payeur_entreprise_id) REFERENCES entreprise (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_attempt DROP FOREIGN KEY FK_AB6AFC65DAC5993');
        $this->addSql('DROP INDEX IDX_AB6AFC65DAC5993 ON quiz_attempt');
        $this->addSql('ALTER TABLE quiz_attempt DROP inscription_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE billing_entite_connect DROP FOREIGN KEY FK_253342EE9BEA957A');
        $this->addSql('ALTER TABLE billing_facture_checkout DROP FOREIGN KEY FK_3E412B9B7F2DEE08');
        $this->addSql('ALTER TABLE billing_facture_checkout DROP FOREIGN KEY FK_3E412B9B9BEA957A');
        $this->addSql('ALTER TABLE billing_facture_checkout DROP FOREIGN KEY FK_3E412B9BBEE8EAEB');
        $this->addSql('ALTER TABLE billing_facture_checkout DROP FOREIGN KEY FK_3E412B9B1BF97EC7');
        $this->addSql('DROP TABLE billing_entite_connect');
        $this->addSql('DROP TABLE billing_facture_checkout');
        $this->addSql('ALTER TABLE quiz_attempt ADD inscription_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC65DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('CREATE INDEX IDX_AB6AFC65DAC5993 ON quiz_attempt (inscription_id)');
    }
}
