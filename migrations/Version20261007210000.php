<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261007210000 extends AbstractMigration
{
    public function getDescription(): string {return 'Portefeuille entreprise des commerciaux et historique des relances';}
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE session ADD entreprise_cliente_id INT DEFAULT NULL, ADD INDEX IDX_SESSION_CLIENT (entreprise_cliente_id), ADD CONSTRAINT FK_SESSION_CLIENT FOREIGN KEY (entreprise_cliente_id) REFERENCES entreprise (id) ON DELETE SET NULL');
        $this->addSql("CREATE TABLE commercial_activity (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, author_id INT NOT NULL, module VARCHAR(30) NOT NULL, record_id INT NOT NULL, title VARCHAR(180) NOT NULL, content LONGTEXT NOT NULL, kind VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', due_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_ACTIVITY_TARGET (entite_id,module,record_id), INDEX IDX_ACTIVITY_AUTHOR (author_id), PRIMARY KEY(id), CONSTRAINT FK_ACTIVITY_TENANT FOREIGN KEY(entite_id) REFERENCES entite(id) ON DELETE CASCADE, CONSTRAINT FK_ACTIVITY_AUTHOR FOREIGN KEY(author_id) REFERENCES utilisateur(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void {$this->addSql('DROP TABLE commercial_activity');$this->addSql('ALTER TABLE session DROP FOREIGN KEY FK_SESSION_CLIENT, DROP entreprise_cliente_id');}
}
