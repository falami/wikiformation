<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Dossiers de préparation BPF par organisme et exercice comptable'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bpf_declaration (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, updated_by_id INT DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, data JSON NOT NULL, updated_at DATETIME NOT NULL, version INT DEFAULT 1 NOT NULL, INDEX IDX_BPF_ENTITE (entite_id), INDEX IDX_BPF_ACTOR (updated_by_id), UNIQUE INDEX uniq_bpf_period (entite_id, start_date, end_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE bpf_declaration ADD CONSTRAINT FK_BPF_ENTITE FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE, ADD CONSTRAINT FK_BPF_ACTOR FOREIGN KEY (updated_by_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE bpf_declaration'); }
}
