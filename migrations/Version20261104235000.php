<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261104235000 extends AbstractMigration
{
    public function getDescription(): string { return 'Propriété des modèles, filiation, archivage et verrouillage à la première attribution.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE evaluation_template ADD trainer_owner_id INT DEFAULT NULL, ADD source_template_id INT DEFAULT NULL, ADD assigned_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX idx_eval_owner ON evaluation_template (trainer_owner_id)');
        $this->addSql('CREATE INDEX idx_eval_source ON evaluation_template (source_template_id)');
        $this->addSql('ALTER TABLE evaluation_template ADD CONSTRAINT fk_eval_owner FOREIGN KEY (trainer_owner_id) REFERENCES utilisateur (id) ON DELETE RESTRICT, ADD CONSTRAINT fk_eval_source FOREIGN KEY (source_template_id) REFERENCES evaluation_template (id) ON DELETE RESTRICT');
        $this->addSql('UPDATE evaluation_template t SET assigned_at = (SELECT MIN(d.created_at) FROM evaluation_dossier d WHERE d.template_id = t.id)');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('La propriété et le verrouillage des modèles doivent être conservés.');
    }
}
