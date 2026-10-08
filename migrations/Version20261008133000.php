<?php

declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20261008133000 extends AbstractMigration
{
    public function getDescription(): string { return 'Paramètres des rappels formateurs par organisme et journal anti-doublon des e-mails'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entite_preferences ADD trainer_reminders JSON DEFAULT NULL');
        $this->addSql("CREATE TABLE trainer_reminder_delivery (id INT AUTO_INCREMENT NOT NULL, entite_id INT NOT NULL, scope_key VARCHAR(160) NOT NULL, sequence_number INT NOT NULL, kind VARCHAR(30) NOT NULL, recipient VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_REMINDER_ENTITE (entite_id), UNIQUE INDEX UNIQ_TRAINER_DELIVERY (entite_id, scope_key, sequence_number), PRIMARY KEY(id), CONSTRAINT FK_REMINDER_ENTITE FOREIGN KEY (entite_id) REFERENCES entite(id) ON DELETE CASCADE) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB");
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trainer_reminder_delivery');
        $this->addSql('ALTER TABLE entite_preferences DROP trainer_reminders');
    }
}
