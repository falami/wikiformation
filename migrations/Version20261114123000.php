<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114123000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add electrical habilitation as an optional, unpublished module; preserve existing dossiers.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("INSERT INTO billing_module (code, name, description, monthly_price, trial_days, active, included) VALUES ('habilitation_electrique', 'Habilitations électriques', 'Accompagnez chaque parcours : évaluations, avis du formateur, décision de l’entreprise et titre du stagiaire. Centralisez les documents signés et leur historique dans un espace partagé par votre organisme.', 2000, 30, 0, 0)");
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve module subscriptions and access history.');
    }
}
