<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025193000 extends AbstractMigration
{
    public function getDescription(): string { return 'Décompte annuel unique des stagiaires par organisme, avec reprise des inscriptions et des comptes stagiaires existants.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_learner_year_usage (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, utilisateur_id INT DEFAULT NULL, year INT NOT NULL, INDEX IDX_LEARNER_ENTITE (entite_id), INDEX IDX_LEARNER_USER (utilisateur_id), UNIQUE INDEX uniq_learner_entite_year (entite_id, utilisateur_id, year), PRIMARY KEY(id), CONSTRAINT FK_LEARNER_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE, CONSTRAINT FK_LEARNER_USER FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql("INSERT IGNORE INTO billing_learner_year_usage (entite_id, utilisateur_id, year) SELECT entite_id, utilisateur_id, YEAR(date_creation) FROM utilisateur_entite WHERE date_creation IS NOT NULL AND status = 'active' AND JSON_CONTAINS(roles, '\"TENANT_STAGIAIRE\"') = 1");
        $this->addSql('INSERT IGNORE INTO billing_learner_year_usage (entite_id, utilisateur_id, year) SELECT entite_id, stagiaire_id, YEAR(date_creation) FROM inscription WHERE date_creation IS NOT NULL AND stagiaire_id IS NOT NULL');
        $this->addSql('UPDATE billing_entite_usage_year SET apprenants_count = 0');
        $this->addSql('INSERT INTO billing_entite_usage_year (entite_id, year, apprenants_count, updated_at) SELECT entite_id, year, COUNT(*), NOW() FROM billing_learner_year_usage GROUP BY entite_id, year ON DUPLICATE KEY UPDATE apprenants_count = VALUES(apprenants_count), updated_at = NOW()');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Le registre conserve la consommation annuelle après suppression des inscriptions.'); }
}
