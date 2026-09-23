<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserve les fractions d’heure des attestations, notamment les demi-journées de 3,5 heures.';
    }

    public function isTransactional(): bool { return false; }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');
        $this->addSql('ALTER TABLE attestation MODIFY duree_heures DOUBLE PRECISION DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le retour à un entier tronquerait les durées des attestations.');
    }
}
