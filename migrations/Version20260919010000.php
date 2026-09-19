<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les intitulés et durées personnalisés, les noms libres et l’effectif prévisionnel des conventions.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');

        $this->addSql('ALTER TABLE convention_contrat ADD intitule_formation VARCHAR(255) DEFAULT NULL, ADD duree_formation VARCHAR(255) DEFAULT NULL, ADD participants_libres LONGTEXT DEFAULT NULL, ADD effectif_previsionnel INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le retour arrière supprimerait les informations personnalisées et les participants déclarés dans les conventions.');
    }
}
