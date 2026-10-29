<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261029150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Intervenants réels couverts par un contrat formateur de la session.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE contrat_formateur_intervenant (contrat_id INT NOT NULL, formateur_id INT NOT NULL, INDEX IDX_CFI_CONTRAT (contrat_id), INDEX IDX_CFI_FORMATEUR (formateur_id), PRIMARY KEY(contrat_id, formateur_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE contrat_formateur_intervenant ADD CONSTRAINT FK_CFI_CONTRAT FOREIGN KEY (contrat_id) REFERENCES contrat_formateur (id) ON DELETE CASCADE, ADD CONSTRAINT FK_CFI_FORMATEUR FOREIGN KEY (formateur_id) REFERENCES formateur (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE contrat_formateur_intervenant'); }
}
