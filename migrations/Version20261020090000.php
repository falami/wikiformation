<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Invitations individuelles à signer une convention, valables 48 heures.'; }
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE convention_signature_invitation (id INT AUTO_INCREMENT NOT NULL, convention_id INT NOT NULL, invited_by_id INT NOT NULL, company_id INT NOT NULL, tenant_id INT NOT NULL, token_hash VARCHAR(64) NOT NULL, email VARCHAR(180) NOT NULL, name VARCHAR(160) NOT NULL, position VARCHAR(120) NOT NULL, document_hash VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_INVITATION_TOKEN (token_hash), INDEX IDX_INVITATION_CONVENTION (convention_id), INDEX IDX_INVITATION_AUTHOR (invited_by_id), PRIMARY KEY(id), CONSTRAINT FK_INVITATION_CONVENTION FOREIGN KEY (convention_id) REFERENCES convention_contrat (id) ON DELETE CASCADE, CONSTRAINT FK_INVITATION_AUTHOR FOREIGN KEY (invited_by_id) REFERENCES utilisateur (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE convention_signature_invitation'); }
}
