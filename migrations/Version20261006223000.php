<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261006223000 extends AbstractMigration
{
    public function getDescription(): string { return 'Forfait global HT facultatif pour les sessions, sans modifier les tarifs existants.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE session ADD montant_global_cents INT DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE session DROP montant_global_cents'); }
}
