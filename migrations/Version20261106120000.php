<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Explicit per-model trainer authorization: no automatic grants, existing dossiers retained.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE evaluation_template_authorized_entite (evaluation_template_id INT NOT NULL, entite_id INT NOT NULL, INDEX IDX_EVAL_AUTH_TENANT (entite_id), PRIMARY KEY(evaluation_template_id, entite_id), CONSTRAINT FK_EVAL_AUTH_TEMPLATE FOREIGN KEY (evaluation_template_id) REFERENCES evaluation_template (id) ON DELETE CASCADE, CONSTRAINT FK_EVAL_AUTH_TENANT FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE evaluation_template_authorized_entite'); }
}
