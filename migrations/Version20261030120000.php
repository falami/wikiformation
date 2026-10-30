<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Réouverture individuelle des questionnaires formateurs pour les relances.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE formateur_satisfaction_assignment ADD reopened_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE formateur_satisfaction_assignment DROP reopened_until'); }
}
