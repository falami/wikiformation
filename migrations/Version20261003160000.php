<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261003160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Historique immuable des PDF de conventions et des rattachements administratifs.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE convention_revision (id INT AUTO_INCREMENT NOT NULL, convention_id INT NOT NULL, pdf LONGBLOB NOT NULL, donnees JSON NOT NULL, motif VARCHAR(255) NOT NULL, auteur VARCHAR(255) NOT NULL, date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CONVENTION_REVISION (convention_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE convention_revision ADD CONSTRAINT FK_CONVENTION_REVISION FOREIGN KEY (convention_id) REFERENCES convention_contrat (id) ON DELETE RESTRICT');
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Les versions signées doivent être conservées.'); }
}
