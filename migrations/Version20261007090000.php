<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261007090000 extends AbstractMigration
{
    public function getDescription(): string { return 'Tests de niveau facultatifs par formation et exclusion des réaffectations automatiques retirées.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation ADD qcm_pre_id INT DEFAULT NULL, ADD qcm_post_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE formation ADD CONSTRAINT FK_FORMATION_QCM_PRE FOREIGN KEY (qcm_pre_id) REFERENCES qcm (id) ON DELETE SET NULL, ADD CONSTRAINT FK_FORMATION_QCM_POST FOREIGN KEY (qcm_post_id) REFERENCES qcm (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE inscription ADD qcm_auto_excluded_phases JSON DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE formation DROP FOREIGN KEY FK_FORMATION_QCM_PRE, DROP FOREIGN KEY FK_FORMATION_QCM_POST');
        $this->addSql('ALTER TABLE formation DROP qcm_pre_id, DROP qcm_post_id');
        $this->addSql('ALTER TABLE inscription DROP qcm_auto_excluded_phases');
    }
}
