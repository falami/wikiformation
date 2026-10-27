<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027024500 extends AbstractMigration
{
    public function getDescription(): string { return 'Suivi des demandes de droits sur les données personnelles ; aucune purge de données existantes.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE privacy_rights_request (reference VARCHAR(40) NOT NULL, owner_id INT DEFAULT NULL, type VARCHAR(20) NOT NULL, details LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, requested_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', due_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', response LONGTEXT DEFAULT NULL, answered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', history JSON NOT NULL, INDEX idx_privacy_request_owner (owner_id), INDEX idx_privacy_request_due (status, due_at), PRIMARY KEY(reference)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE privacy_rights_request ADD CONSTRAINT fk_privacy_request_owner FOREIGN KEY (owner_id) REFERENCES utilisateur (id) ON DELETE SET NULL');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Les demandes et leurs réponses doivent être examinées avant toute suppression.'); }
}
