<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261007100000 extends AbstractMigration
{
    public function getDescription(): string { return 'Présences papier par demi-journée avec traçabilité des corrections administratives.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE inscription ADD presences_manuelles JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE inscription DROP presences_manuelles'); }
}
