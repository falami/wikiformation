<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Étend le stockage des spécialités des formateurs pour la sélection multiple.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formateur MODIFY certifications LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM formateur WHERE CHAR_LENGTH(certifications) > 255') > 0,
            'Des spécialités dépassent 255 caractères : retour arrière impossible sans perte de données.',
        );
        $this->addSql('ALTER TABLE formateur MODIFY certifications VARCHAR(255) DEFAULT NULL');
    }
}
