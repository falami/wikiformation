<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260408125341 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log CHANGE payload payload JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE avoir CHANGE meta meta JSON NOT NULL');
        $this->addSql('ALTER TABLE billing_entite_subscription CHANGE addons addons JSON DEFAULT NULL, CHANGE limits_snapshot limits_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE billing_plan CHANGE seat_limits seat_limits JSON NOT NULL');
        $this->addSql('ALTER TABLE content_block CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE depense CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE devis CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE elearning_block CHANGE meta meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE entite_preferences ADD tampon_organisme_path VARCHAR(255) DEFAULT NULL');
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
        $this->addSql('ALTER TABLE audit_log CHANGE payload payload LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE avoir CHANGE meta meta LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE billing_entite_subscription CHANGE addons addons LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE limits_snapshot limits_snapshot LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE billing_plan CHANGE seat_limits seat_limits LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE content_block CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE depense CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE devis CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE elearning_block CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE entite_preferences DROP tampon_organisme_path');
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
        $this->addSql('ALTER TABLE satisfaction_attempt CHANGE answers answers LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE satisfaction_question CHANGE choices choices LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE session CHANGE pieces_obligatoires pieces_obligatoires LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE tax_computation CHANGE breakdown breakdown LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE profile_snapshot profile_snapshot LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE rules_snapshot rules_snapshot LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE tax_rule CHANGE conditions conditions LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE meta meta LONGTEXT DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('ALTER TABLE utilisateur_entite CHANGE roles roles LONGTEXT NOT NULL COLLATE `utf8mb4_bin`');
    }
}
