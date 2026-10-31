<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261031162000 extends AbstractMigration
{
    public function getDescription(): string { return 'Préférence personnelle par organisme pour les cartes et trajets automatiques du formateur.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE utilisateur_entite ADD automatic_trainer_travel TINYINT(1) DEFAULT 0 NOT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE utilisateur_entite DROP automatic_trainer_travel'); }
}
