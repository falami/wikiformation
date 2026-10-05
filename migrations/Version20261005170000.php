<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261005170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Signature personnelle privée, liée au compte utilisateur.'; }
    public function up(Schema $schema): void {
        $this->addSql('CREATE TABLE personal_signature (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, image MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_PERSONAL_SIGNATURE_OWNER (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE personal_signature ADD CONSTRAINT FK_PERSONAL_SIGNATURE_OWNER FOREIGN KEY (owner_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE personal_signature'); }
}
