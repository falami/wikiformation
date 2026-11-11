<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261111234500 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist unfinished CACES practical assessments independently of signed results.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE caces_attempt ADD practice_draft JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve unfinished assessment scores.'); }
}
