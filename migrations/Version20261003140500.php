<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003140500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identifiant stable par organisme pour le questionnaire standard des QR participants.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE satisfaction_template ADD system_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_satisfaction_template_system ON satisfaction_template (entite_id, system_key)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les identifiants des questionnaires pouvant avoir reçu des réponses.');
    }
}
