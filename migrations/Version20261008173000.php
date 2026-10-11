<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008173000 extends AbstractMigration
{
    public function getDescription(): string { return 'Préférences de déplacement des formateurs par organisme'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE entite_preferences ADD trainer_travel JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE entite_preferences DROP trainer_travel'); }
}
