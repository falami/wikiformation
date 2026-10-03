<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nouvelle offre Excellence à 150 € HT/mois, sans réutiliser les tarifs ni modifier les abonnements historiques.';
    }

    public function up(Schema $schema): void
    {
        // A separate commercial version preserves existing contracts and their Stripe price IDs.
        $this->addSql('INSERT INTO billing_plan (code, name, tagline, max_apprenants_an, max_utilisateurs, max_formateurs, max_entreprises, max_prospects, support_prioritaire, is_active, price_monthly_cents, price_yearly_cents, accent_color, badge, ordre, seat_limits) SELECT ?, ?, ?, 0, 0, 0, 0, 0, 1, 1, 15000, NULL, ?, NULL, 4, ? WHERE NOT EXISTS (SELECT 1 FROM billing_plan WHERE UPPER(code) = ?)', [
            'EXCELLENCE_2026', 'Excellence', 'Pour les organismes qui veulent grandir sans limite de volume.', '#b8913c',
            json_encode(['admins' => 0, 'formateurs' => 0], JSON_THROW_ON_ERROR), 'EXCELLENCE_2026',
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Cette offre peut être utilisée par des abonnements ; leur historique doit être conservé.');
    }
}
