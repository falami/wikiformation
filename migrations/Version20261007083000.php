<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261007083000 extends AbstractMigration
{
    public function getDescription(): string { return 'Clôture manuelle du suivi des émargements, date et auteur.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE session ADD emargement_cloture_at DATETIME DEFAULT NULL, ADD emargement_cloture_par VARCHAR(255) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE session DROP emargement_cloture_at, DROP emargement_cloture_par'); }
}
