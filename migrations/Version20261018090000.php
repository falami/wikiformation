<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Conserve la date de génération du PDF de convention.'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE convention_contrat ADD pdf_generated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\''); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE convention_contrat DROP pdf_generated_at'); }
}
