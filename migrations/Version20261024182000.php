<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autoriser une description multiligne du public des formations sans limite de 255 caractères';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation MODIFY public LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf((int) $this->connection->fetchOne('SELECT COUNT(*) FROM formation WHERE CHAR_LENGTH(public) > 255') > 0, 'Retour impossible sans tronquer les descriptions du public.');
        $this->addSql('ALTER TABLE formation MODIFY public VARCHAR(255) DEFAULT NULL');
    }
}
