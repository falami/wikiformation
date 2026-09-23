<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pause facultative par créneau ; NULL active le calcul automatique des heures pédagogiques.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');
        $this->addSql('ALTER TABLE session_jour ADD pause_minutes INT DEFAULT NULL');
    }

    public function isTransactional(): bool { return false; }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les pauses explicites déjà renseignées.');
    }
}
