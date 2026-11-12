<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261112020000 extends AbstractMigration
{
    public function getDescription(): string { return 'Share one theory attempt across independently assessed CACES categories; preserve existing records.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE caces_attempt ADD theory_source_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CACES_THEORY_SOURCE ON caces_attempt (theory_source_id)');
        $this->addSql('ALTER TABLE caces_attempt ADD CONSTRAINT FK_CACES_THEORY_SOURCE FOREIGN KEY (theory_source_id) REFERENCES caces_attempt (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Preserve shared theory records.'); }
}
