<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260307083643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE formation_public_host (formation_id INT NOT NULL, public_host_id INT NOT NULL, INDEX IDX_EB8771F75200282E (formation_id), INDEX IDX_EB8771F744668E37 (public_host_id), PRIMARY KEY(formation_id, public_host_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE public_host (id INT AUTO_INCREMENT NOT NULL, host VARCHAR(190) NOT NULL, name VARCHAR(120) NOT NULL, logo_path VARCHAR(190) DEFAULT NULL, primary_color VARCHAR(20) NOT NULL, secondary_color VARCHAR(20) NOT NULL, tertiary_color VARCHAR(20) DEFAULT NULL, quaternary_color VARCHAR(20) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, catalogue_enabled TINYINT(1) DEFAULT 1 NOT NULL, calendar_enabled TINYINT(1) DEFAULT 1 NOT NULL, elearning_enabled TINYINT(1) DEFAULT 0 NOT NULL, shop_enabled TINYINT(1) DEFAULT 0 NOT NULL, restrict_to_assigned_formations TINYINT(1) DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_public_host_host (host), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE formation_public_host ADD CONSTRAINT FK_EB8771F75200282E FOREIGN KEY (formation_id) REFERENCES formation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE formation_public_host ADD CONSTRAINT FK_EB8771F744668E37 FOREIGN KEY (public_host_id) REFERENCES public_host (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE formation_public_host DROP FOREIGN KEY FK_EB8771F75200282E');
        $this->addSql('ALTER TABLE formation_public_host DROP FOREIGN KEY FK_EB8771F744668E37');
        $this->addSql('DROP TABLE formation_public_host');
        $this->addSql('DROP TABLE public_host');
    }
}
