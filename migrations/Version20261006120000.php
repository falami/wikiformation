<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261006120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Modèles, dossiers et versions signées des habilitations électriques.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE habilitation_template (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, titre VARCHAR(180) NOT NULL, schema_definition JSON NOT NULL, active TINYINT(1) NOT NULL, version INT DEFAULT 1 NOT NULL, INDEX IDX_4E6416929BEA957A (entite_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE habilitation_dossier (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, inscription_id INT NOT NULL, template_id INT NOT NULL, formateur_id INT NOT NULL, entreprise_id INT NOT NULL, schema_definition JSON NOT NULL, trainer_data JSON NOT NULL, employer_data JSON NOT NULL, state VARCHAR(180) NOT NULL, active TINYINT(1) NOT NULL, deleted TINYINT(1) NOT NULL, avis_token VARCHAR(32) DEFAULT NULL, titre_token VARCHAR(32) DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_EF1B2DDE9BEA957A (entite_id), INDEX IDX_EF1B2DDE5DA0FB8 (template_id), INDEX IDX_EF1B2DDE155D8F51 (formateur_id), INDEX IDX_EF1B2DDEA4AEAFEA (entreprise_id), INDEX IDX_EF1B2DDE9BEA957AA393D2FB4B1EFC02 (entite_id, state, active), UNIQUE INDEX uniq_habilitation_inscription (inscription_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE habilitation_revision (id INT AUTO_INCREMENT NOT NULL, dossier_id INT NOT NULL, actor_id INT NOT NULL, token VARCHAR(32) NOT NULL, kind VARCHAR(180) NOT NULL, snapshot JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_B4671CDD5F37A13B (token), INDEX IDX_B4671CDD611C0C56 (dossier_id), INDEX IDX_B4671CDD10DAF24A (actor_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE habilitation_template ADD CONSTRAINT FK_4E6416929BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_dossier ADD CONSTRAINT FK_EF1B2DDE9BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_dossier ADD CONSTRAINT FK_EF1B2DDE5DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_dossier ADD CONSTRAINT FK_EF1B2DDE5DA0FB8 FOREIGN KEY (template_id) REFERENCES habilitation_template (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_dossier ADD CONSTRAINT FK_EF1B2DDE155D8F51 FOREIGN KEY (formateur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_dossier ADD CONSTRAINT FK_EF1B2DDEA4AEAFEA FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_revision ADD CONSTRAINT FK_B4671CDD611C0C56 FOREIGN KEY (dossier_id) REFERENCES habilitation_dossier (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE habilitation_revision ADD CONSTRAINT FK_B4671CDD10DAF24A FOREIGN KEY (actor_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE formation ADD habilitation_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE formation ADD CONSTRAINT FK_FORMATION_HABILITATION_TEMPLATE FOREIGN KEY (habilitation_template_id) REFERENCES habilitation_template (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_FORMATION_HABILITATION_TEMPLATE ON formation (habilitation_template_id)');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les évaluations et signatures doivent être conservées.');
    }
}
