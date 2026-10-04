<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261004040000 extends AbstractMigration
{
    public function getDescription(): string { return 'Taux de TVA modifiable du catalogue, 20 % par défaut.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE formation ADD taux_tva DOUBLE PRECISION NOT NULL DEFAULT 20'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE formation DROP taux_tva'); }
}
