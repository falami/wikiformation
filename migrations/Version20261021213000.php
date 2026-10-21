<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021213000 extends AbstractMigration
{
    public function getDescription(): string { return 'Nom de société du formateur par organisme'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE formateur ADD nom_societe VARCHAR(255) DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE formateur DROP nom_societe'); }
}
