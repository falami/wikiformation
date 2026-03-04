<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260303225445 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE questionnaire_satisfaction DROP FOREIGN KEY FK_B16908015DAC5993');
        $this->addSql('ALTER TABLE questionnaire_satisfaction DROP FOREIGN KEY FK_B1690801613FECDF');
        $this->addSql('ALTER TABLE questionnaire_satisfaction DROP FOREIGN KEY FK_B169080173A201E5');
        $this->addSql('ALTER TABLE questionnaire_satisfaction DROP FOREIGN KEY FK_B16908019BEA957A');
        $this->addSql('ALTER TABLE questionnaire_satisfaction DROP FOREIGN KEY FK_B1690801BBA93DD6');
        $this->addSql('DROP TABLE questionnaire_satisfaction');
        $this->addSql('ALTER TABLE audit_log CHANGE payload payload JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE avoir CHANGE meta meta JSON NOT NULL');
        $this->addSql('ALTER TABLE billing_entite_subscription CHANGE addons addons JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_plan CHANGE seat_limits seat_limits JSON NOT NULL');
        $this->addSql('ALTER TABLE content_block CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE depense CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE devis CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE elearning_block CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE facture CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_profile CHANGE options options JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE formateur_satisfaction_attempt CHANGE answers answers JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE formateur_satisfaction_question CHANGE choices choices JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE inscription CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE paiement CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE qcm_attempt CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz CHANGE settings settings JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE rapport_formateur CHANGE criteres criteres JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation CHANGE documents documents JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE satisfaction_assignment ADD inscription_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE satisfaction_assignment ADD CONSTRAINT FK_12CAB4495DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_12CAB4495DAC5993 ON satisfaction_assignment (inscription_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sat_inscription_template ON satisfaction_assignment (inscription_id, template_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sat_session_stagiaire_template ON satisfaction_assignment (session_id, stagiaire_id, template_id)');
        $this->addSql('ALTER TABLE satisfaction_attempt CHANGE answers answers JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE satisfaction_question CHANGE choices choices JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE session CHANGE pieces_obligatoires pieces_obligatoires JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tax_computation CHANGE breakdown breakdown JSON DEFAULT NULL, CHANGE profile_snapshot profile_snapshot JSON DEFAULT NULL, CHANGE rules_snapshot rules_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE tax_rule CHANGE conditions conditions JSON DEFAULT NULL, CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles JSON NOT NULL');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE questionnaire_satisfaction (id INT AUTO_INCREMENT NOT NULL, session_id INT NOT NULL, inscription_id INT NOT NULL, stagiaire_id INT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, type VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_0900_ai_ci`, reponses LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, note_globale INT DEFAULT NULL, submitted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', started_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_creation DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_sat_once (entite_id, session_id, stagiaire_id, type), INDEX IDX_B1690801613FECDF (session_id), INDEX IDX_B16908015DAC5993 (inscription_id), INDEX IDX_B1690801BBA93DD6 (stagiaire_id), INDEX IDX_B16908019BEA957A (entite_id), INDEX IDX_B169080173A201E5 (createur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE questionnaire_satisfaction ADD CONSTRAINT FK_B16908015DAC5993 FOREIGN KEY (inscription_id) REFERENCES inscription (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE questionnaire_satisfaction ADD CONSTRAINT FK_B1690801613FECDF FOREIGN KEY (session_id) REFERENCES session (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE questionnaire_satisfaction ADD CONSTRAINT FK_B169080173A201E5 FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE questionnaire_satisfaction ADD CONSTRAINT FK_B16908019BEA957A FOREIGN KEY (entite_id) REFERENCES entite (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE questionnaire_satisfaction ADD CONSTRAINT FK_B1690801BBA93DD6 FOREIGN KEY (stagiaire_id) REFERENCES utilisateur (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE audit_log CHANGE payload payload LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE avoir CHANGE meta meta LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE billing_entite_subscription CHANGE addons addons LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE billing_plan CHANGE seat_limits seat_limits LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE content_block CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE depense CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE devis CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE elearning_block CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE facture CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE fiscal_profile CHANGE options options LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE formateur_satisfaction_attempt CHANGE answers answers LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE formateur_satisfaction_question CHANGE choices choices LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE inscription CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE paiement CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE qcm_attempt CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE quiz CHANGE settings settings LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE rapport_formateur CHANGE criteres criteres LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE reservation CHANGE documents documents LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE satisfaction_assignment DROP FOREIGN KEY FK_12CAB4495DAC5993');
        $this->addSql('DROP INDEX IDX_12CAB4495DAC5993 ON satisfaction_assignment');
        $this->addSql('DROP INDEX uniq_sat_inscription_template ON satisfaction_assignment');
        $this->addSql('DROP INDEX uniq_sat_session_stagiaire_template ON satisfaction_assignment');
        $this->addSql('ALTER TABLE satisfaction_assignment DROP inscription_id');
        $this->addSql('ALTER TABLE satisfaction_attempt CHANGE answers answers LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE satisfaction_question CHANGE choices choices LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE session CHANGE pieces_obligatoires pieces_obligatoires LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE tax_computation CHANGE breakdown breakdown LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE profile_snapshot profile_snapshot LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE rules_snapshot rules_snapshot LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE tax_rule CHANGE conditions conditions LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
    }
}
