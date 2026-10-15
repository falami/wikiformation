<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261015235000 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit trainer contract and remuneration exemption, disabled by default.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE formateur ADD sans_contrat_remuneration TINYINT(1) DEFAULT 0 NOT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE formateur DROP sans_contrat_remuneration'); }
}
