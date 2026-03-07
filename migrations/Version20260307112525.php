<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260307112525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE formation ADD exclude_from_global_catalogue TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE public_host DROP FOREIGN KEY FK_B47A512C9BEA957A');
        $this->addSql('ALTER TABLE public_host ADD show_all_public_formations TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE public_host ADD CONSTRAINT FK_B47A512C9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE formation DROP exclude_from_global_catalogue');
        $this->addSql('ALTER TABLE public_host DROP FOREIGN KEY FK_B47A512C9BEA957A');
        $this->addSql('ALTER TABLE public_host DROP show_all_public_formations');
        $this->addSql('ALTER TABLE public_host ADD CONSTRAINT FK_B47A512C9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
    }
}
