<?php

declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018101500 extends AbstractMigration
{
    public function getDescription(): string { return 'Permet plusieurs formateurs par dossier d’habilitation, en conservant les affectations existantes.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE habilitation_dossier_formateur (dossier_id INT NOT NULL, utilisateur_id INT NOT NULL, INDEX IDX_HAB_TRAINER (utilisateur_id), PRIMARY KEY(dossier_id, utilisateur_id), CONSTRAINT FK_HAB_DOSSIER FOREIGN KEY (dossier_id) REFERENCES habilitation_dossier (id) ON DELETE CASCADE, CONSTRAINT FK_HAB_TRAINER FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('INSERT INTO habilitation_dossier_formateur (dossier_id, utilisateur_id) SELECT id, formateur_id FROM habilitation_dossier');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE habilitation_dossier_formateur'); }
}
