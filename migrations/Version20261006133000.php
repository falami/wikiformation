<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261006133000 extends AbstractMigration
{
    public function getDescription(): string { return 'TVA personnalisable des sessions ; null conserve la TVA héritée de la formation.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE session ADD taux_tva DOUBLE PRECISION DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE session DROP taux_tva'); }
}
