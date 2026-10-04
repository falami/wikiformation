<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004030000 extends AbstractMigration
{
    public function getDescription(): string { return 'Entreprises multiples des stagiaires et montant personnalisé des conventions.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stagiaire_entreprise (utilisateur_id INT NOT NULL, entreprise_id INT NOT NULL, INDEX IDX_STAGIAIRE_ENTREPRISE_USER (utilisateur_id), INDEX IDX_STAGIAIRE_ENTREPRISE_COMPANY (entreprise_id), PRIMARY KEY(utilisateur_id, entreprise_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
        $this->addSql('ALTER TABLE stagiaire_entreprise ADD CONSTRAINT FK_STAGIAIRE_ENTREPRISE_USER FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE stagiaire_entreprise ADD CONSTRAINT FK_STAGIAIRE_ENTREPRISE_COMPANY FOREIGN KEY (entreprise_id) REFERENCES entreprise (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE convention_contrat ADD montant_ht_cents INT DEFAULT NULL, ADD taux_tva DOUBLE PRECISION NOT NULL DEFAULT 0');
    }
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les rattachements et les montants contractuels saisis.');
    }
}
