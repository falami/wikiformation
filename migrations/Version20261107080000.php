<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore notification receipts omitted by ORM-based database initialization.';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('notification_receipt')) {
            return;
        }

        $this->addSql('CREATE TABLE notification_receipt (entite_id INT NOT NULL, utilisateur_id INT NOT NULL, item_key VARCHAR(190) NOT NULL, read_at DATETIME DEFAULT NULL, archived TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY(entite_id, utilisateur_id, item_key), FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE, FOREIGN KEY (utilisateur_id) REFERENCES utilisateur(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Preserve existing notification read and archive states.');
    }
}
