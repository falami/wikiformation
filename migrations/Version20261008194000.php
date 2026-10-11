<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008194000 extends AbstractMigration
{
    public function getDescription(): string { return 'Part des enseignements à distance par session, sans présumer du présentiel pour les dossiers existants'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE session ADD taux_distanciel DOUBLE PRECISION DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE session DROP taux_distanciel'); }
}
