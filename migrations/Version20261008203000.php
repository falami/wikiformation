<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008203000 extends AbstractMigration
{
    public function getDescription(): string { return 'Isoler le compte distant PA entre espaces afin de ne jamais partager les factures reçues'; }
    public function up(Schema $schema): void { $this->addSql('CREATE UNIQUE INDEX uniq_einvoice_remote_company ON einvoice_connection (environment, company_id)'); }
    public function down(Schema $schema): void { $this->addSql('DROP INDEX uniq_einvoice_remote_company ON einvoice_connection'); }
}
