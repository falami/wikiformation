<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015154000 extends AbstractMigration
{
    public function getDescription(): string { return 'Règles de classement des imports de dépenses par organisme'; }
    public function up(Schema $schema): void {
        $this->addSql('CREATE TABLE expense_import_rule (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, category_id INT NOT NULL, supplier_id INT NOT NULL, keyword VARCHAR(180) NOT NULL, priority INT NOT NULL, enabled TINYINT(1) NOT NULL, deductible TINYINT(1) NOT NULL, percentage DOUBLE PRECISION NOT NULL, INDEX IDX_EXP_RULE_ENTITE (entite_id), INDEX IDX_EXP_RULE_CAT (category_id), INDEX IDX_EXP_RULE_SUP (supplier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE expense_import_rule ADD CONSTRAINT FK_EXP_RULE_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE, ADD CONSTRAINT FK_EXP_RULE_CAT FOREIGN KEY (category_id) REFERENCES depense_categorie (id) ON DELETE CASCADE, ADD CONSTRAINT FK_EXP_RULE_SUP FOREIGN KEY (supplier_id) REFERENCES depense_fournisseur (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE expense_import_rule'); }
}
