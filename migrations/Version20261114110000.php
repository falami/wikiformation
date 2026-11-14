<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Tenant module subscriptions, gifts and audit history; seed CACES draft at EUR 200/month with 30 trial days.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE billing_module (code VARCHAR(60) NOT NULL, name VARCHAR(120) NOT NULL, description LONGTEXT NOT NULL, monthly_price INT NOT NULL, trial_days INT NOT NULL, active TINYINT(1) NOT NULL, stripe_price_id VARCHAR(255) DEFAULT NULL, included TINYINT(1) NOT NULL, PRIMARY KEY(code)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("CREATE TABLE billing_module_access (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, module_code VARCHAR(60) NOT NULL, status VARCHAR(40) NOT NULL, subscription_id VARCHAR(255) DEFAULT NULL, customer_id VARCHAR(255) DEFAULT NULL, checkout_id VARCHAR(255) DEFAULT NULL, price_id VARCHAR(255) DEFAULT NULL, price INT DEFAULT NULL, period_end DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', cancel_at_period_end TINYINT(1) NOT NULL, trial_used TINYINT(1) NOT NULL, gift TINYINT(1) NOT NULL, gift_end DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', history JSON NOT NULL, UNIQUE INDEX module_subscription_unique (subscription_id), UNIQUE INDEX module_tenant_unique (entite_id, module_code), INDEX module_entite_idx (entite_id), INDEX module_code_idx (module_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE billing_module_access ADD CONSTRAINT module_access_entite_fk FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE billing_module_access ADD CONSTRAINT module_access_module_fk FOREIGN KEY (module_code) REFERENCES billing_module (code)');
        $this->addSql("INSERT INTO billing_module (code, name, description, monthly_price, trial_days, active, included) VALUES ('caces', 'Certification CACES®', 'Préparez les tests, accompagnez vos candidats et retrouvez les preuves de chaque parcours dans un dossier unique. Bibliothèque, évaluations, certificats et registre : tout votre suivi CACES au même endroit.', 20000, 30, 0, 0)");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve module billing identifiers and access history.'); }
}
