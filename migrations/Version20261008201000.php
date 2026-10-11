<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008201000 extends AbstractMigration
{
    public function getDescription(): string { return 'Préférences de facturation et mentions validées par organisme'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE entite_preferences ADD invoice_preferences JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE entite_preferences DROP invoice_preferences'); }
}
