<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattache les conventions à leur devis et autorise plusieurs conventions par destinataire et session.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Cette migration nécessite MySQL ou MariaDB.');

        $table = $schema->getTable('convention_contrat');

        // Certaines installations ont été créées avec schema:update : ne supprimer
        // que les index présents, sans toucher aux conventions ni à leurs numéros.
        foreach (['entreprise', 'stagiaire'] as $recipient) {
            $index = 'uniq_conv_entite_session_' . $recipient;
            if ($table->hasIndex($index)) {
                $this->addSql(sprintf(
                    'ALTER TABLE convention_contrat DROP INDEX %s, ADD INDEX idx_conv_entite_session_%s (entite_id, session_id, %s_id)',
                    $index,
                    $recipient,
                    $recipient
                ));
            } elseif (!$table->hasIndex('idx_conv_entite_session_' . $recipient)) {
                $this->addSql(sprintf(
                    'CREATE INDEX idx_conv_entite_session_%s ON convention_contrat (entite_id, session_id, %s_id)',
                    $recipient,
                    $recipient
                ));
            }
        }

        $this->addSql('ALTER TABLE convention_contrat ADD devis_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_convention_devis ON convention_contrat (devis_id)');
        $this->addSql('ALTER TABLE convention_contrat ADD CONSTRAINT fk_convention_devis FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Le nouveau modèle autorise des données que les anciennes contraintes
        // refuseraient. Un retour arrière automatique perdrait aussi la traçabilité.
        $this->throwIrreversibleMigrationException('Le retour arrière supprimerait les liens aux devis et pourrait interdire des conventions existantes.');
    }
}
