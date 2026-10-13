<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261013023000 extends AbstractMigration
{
    public function getDescription(): string { return 'Atelier de documents : brouillons, versions publiées et affectations par organisme'; }
    public function up(Schema $schema): void {
        $this->addSql("CREATE TABLE document_template (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, kind VARCHAR(60) NOT NULL, name VARCHAR(160) NOT NULL, config JSON NOT NULL, history JSON NOT NULL, version INT DEFAULT 1 NOT NULL, updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX studio_model_tenant_kind (entite_id, kind), PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
        $this->addSql("CREATE TABLE document_template_binding (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, template_id INT NOT NULL, kind VARCHAR(60) NOT NULL, config JSON NOT NULL, published_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX studio_tenant_kind (entite_id, kind), INDEX studio_binding_model (template_id), PRIMARY KEY(id), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE, FOREIGN KEY (template_id) REFERENCES document_template(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE document_template_binding'); $this->addSql('DROP TABLE document_template'); }
}
