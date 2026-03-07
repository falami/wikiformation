<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260307103014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE public_host ADD entite_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE public_host ADD CONSTRAINT FK_B47A512C9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id)');
        $this->addSql('CREATE INDEX IDX_B47A512C9BEA957A ON public_host (entite_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE public_host DROP FOREIGN KEY FK_B47A512C9BEA957A');
        $this->addSql('DROP INDEX IDX_B47A512C9BEA957A ON public_host');
        $this->addSql('ALTER TABLE public_host DROP entite_id');
    }
}
