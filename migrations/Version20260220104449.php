<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260220104449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX uniq_attestation_sequence ON attestation_sequence (entite_id, year)');
        $this->addSql('CREATE UNIQUE INDEX uniq_entite_year ON billing_entite_usage_year (entite_id, year)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_attestation_sequence ON attestation_sequence');
        $this->addSql('DROP INDEX uniq_entite_year ON billing_entite_usage_year');
    }
}
