<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260220104449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create unique indexes if missing (safe on existing prod DB).';
    }

    public function up(Schema $schema): void
    {
        // MySQL: pas de CREATE INDEX IF NOT EXISTS -> condition via information_schema + SQL dynamique.

        // attestation_sequence: uniq_attestation_sequence(entite_id, year)
        $this->addSql("
            SET @idx := (
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'attestation_sequence'
                  AND index_name = 'uniq_attestation_sequence'
            );
        ");
        $this->addSql("
            SET @sql := IF(
                @idx = 0,
                'CREATE UNIQUE INDEX uniq_attestation_sequence ON attestation_sequence (entite_id, year)',
                'SELECT 1'
            );
        ");
        $this->addSql("PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;");

        // billing_entite_usage_year: uniq_entite_year(entite_id, year)
        $this->addSql("
            SET @idx2 := (
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'billing_entite_usage_year'
                  AND index_name = 'uniq_entite_year'
            );
        ");
        $this->addSql("
            SET @sql2 := IF(
                @idx2 = 0,
                'CREATE UNIQUE INDEX uniq_entite_year ON billing_entite_usage_year (entite_id, year)',
                'SELECT 1'
            );
        ");
        $this->addSql("PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;");
    }

    public function down(Schema $schema): void
    {
        // Down safe : drop seulement si présent

        $this->addSql("
            SET @d1 := (
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'attestation_sequence'
                  AND index_name = 'uniq_attestation_sequence'
            );
        ");
        $this->addSql("
            SET @s1 := IF(@d1 > 0, 'DROP INDEX uniq_attestation_sequence ON attestation_sequence', 'SELECT 1');
        ");
        $this->addSql("PREPARE dstmt1 FROM @s1; EXECUTE dstmt1; DEALLOCATE PREPARE dstmt1;");

        $this->addSql("
            SET @d2 := (
                SELECT COUNT(1)
                FROM information_schema.statistics
                WHERE table_schema = DATABASE()
                  AND table_name = 'billing_entite_usage_year'
                  AND index_name = 'uniq_entite_year'
            );
        ");
        $this->addSql("
            SET @s2 := IF(@d2 > 0, 'DROP INDEX uniq_entite_year ON billing_entite_usage_year', 'SELECT 1');
        ");
        $this->addSql("PREPARE dstmt2 FROM @s2; EXECUTE dstmt2; DEALLOCATE PREPARE dstmt2;");
    }
}
