<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261028230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historique financier plateforme et coûts fournisseurs, sans modification des encaissements.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE platform_revenue (id VARCHAR(100) NOT NULL, entite_id INT NOT NULL, currency VARCHAR(3) NOT NULL, live TINYINT(1) NOT NULL, paid_at DATETIME NOT NULL, synced_at DATETIME NOT NULL, ht INT DEFAULT NULL, ttc INT NOT NULL, paid INT NOT NULL, refund INT NOT NULL, fee INT DEFAULT NULL, review TINYINT(1) NOT NULL, label VARCHAR(255) NOT NULL, INDEX idx_platform_revenue_entite (entite_id), INDEX idx_platform_revenue_paid (paid_at), PRIMARY KEY(id), CONSTRAINT fk_platform_revenue_entite FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
        $this->addSql("CREATE TABLE platform_cost (id INT AUTO_INCREMENT NOT NULL, entite_id INT DEFAULT NULL, author_id INT NOT NULL, date DATETIME NOT NULL, created_at DATETIME NOT NULL, category VARCHAR(30) NOT NULL, label VARCHAR(255) NOT NULL, reference VARCHAR(255) NOT NULL, ht INT NOT NULL, ttc INT NOT NULL, estimated TINYINT(1) NOT NULL, archived TINYINT(1) NOT NULL, INDEX idx_platform_cost_entite (entite_id), INDEX idx_platform_cost_author (author_id), PRIMARY KEY(id), CONSTRAINT fk_platform_cost_entite FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT, CONSTRAINT fk_platform_cost_author FOREIGN KEY (author_id) REFERENCES utilisateur (id) ON DELETE RESTRICT) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE platform_cost'); $this->addSql('DROP TABLE platform_revenue'); }
}
