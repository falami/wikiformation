<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Brouillons de factures séparés du registre émis, versionnés et liés à leur émission définitive.'; }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE facture_brouillon (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, createur_id INT NOT NULL, facture_emise_id INT DEFAULT NULL, source_devis_id INT DEFAULT NULL, reference VARCHAR(32) NOT NULL, payload JSON NOT NULL, summary JSON NOT NULL, version INT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', archived_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', history JSON NOT NULL, INDEX idx_facture_brouillon_entite (entite_id), INDEX idx_facture_brouillon_createur (createur_id), UNIQUE INDEX uniq_facture_brouillon_reference (reference), UNIQUE INDEX uniq_facture_brouillon_emise (facture_emise_id), UNIQUE INDEX uniq_facture_brouillon_devis (source_devis_id), INDEX idx_facture_brouillon_worklist (entite_id, archived_at, facture_emise_id, updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE facture_brouillon ADD CONSTRAINT fk_facture_brouillon_entite FOREIGN KEY (entite_id) REFERENCES entite (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE facture_brouillon ADD CONSTRAINT fk_facture_brouillon_createur FOREIGN KEY (createur_id) REFERENCES utilisateur (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE facture_brouillon ADD CONSTRAINT fk_facture_brouillon_emise FOREIGN KEY (facture_emise_id) REFERENCES facture (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE facture_brouillon ADD CONSTRAINT fk_facture_brouillon_devis FOREIGN KEY (source_devis_id) REFERENCES devis (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE facture_brouillon');
    }
}
