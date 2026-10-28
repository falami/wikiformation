<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261028230100 extends AbstractMigration
{
    public function getDescription(): string { return 'Affectation des coûts plateforme à une activité.'; }
    public function up(Schema $schema): void { $this->addSql("ALTER TABLE platform_cost ADD activity VARCHAR(30) DEFAULT 'shared' NOT NULL"); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE platform_cost DROP activity'); }
}
