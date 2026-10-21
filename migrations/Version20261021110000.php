<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261021110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Site internet de l’organisme pour les documents PDF.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE entite ADD site_internet VARCHAR(255) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE entite DROP site_internet'); }
}
