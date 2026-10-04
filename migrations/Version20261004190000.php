<?php
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261004190000 extends AbstractMigration
{
    public function getDescription(): string { return 'Importance et suivi administratif des rapports formateurs.'; }
    public function up(Schema $schema): void {
        $this->addSql("ALTER TABLE rapport_formateur ADD importance VARCHAR(20) DEFAULT 'normal' NOT NULL, ADD statut_traitement VARCHAR(20) DEFAULT 'new' NOT NULL, ADD actions_realisees LONGTEXT DEFAULT NULL, ADD historique_traitement JSON DEFAULT NULL");
    }
    public function down(Schema $schema): void {
        $this->addSql('ALTER TABLE rapport_formateur DROP importance, DROP statut_traitement, DROP actions_realisees, DROP historique_traitement');
    }
}
