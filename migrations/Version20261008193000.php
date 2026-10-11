<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008193000 extends AbstractMigration
{
    public function getDescription(): string { return 'Informations publiques vérifiables des formations (accès, handicap, contact, reconnaissance, résultats)'; }
    public function up(Schema $schema): void { $this->addSql('ALTER TABLE formation ADD public_information JSON DEFAULT NULL'); }
    public function down(Schema $schema): void { $this->addSql('ALTER TABLE formation DROP public_information'); }
}
