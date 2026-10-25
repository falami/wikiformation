<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complète le registre annuel des apprenants avec les accès e-learning existants, sans double comptage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('INSERT IGNORE INTO billing_learner_year_usage (entite_id, utilisateur_id, year) SELECT entite_id, stagiaire_id, YEAR(date_creation) FROM elearning_enrollment WHERE date_creation IS NOT NULL AND stagiaire_id IS NOT NULL');
        $this->addSql('INSERT INTO billing_entite_usage_year (entite_id, year, apprenants_count, updated_at) SELECT entite_id, year, COUNT(*), NOW() FROM billing_learner_year_usage GROUP BY entite_id, year ON DUPLICATE KEY UPDATE apprenants_count = VALUES(apprenants_count), updated_at = NOW()');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les apprenants communs au présentiel et au e-learning partagent le même registre.');
    }
}
