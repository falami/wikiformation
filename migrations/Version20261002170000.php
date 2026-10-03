<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Offres mensuelles Solo 29 €, Pro 59 €, Organisme 99 € HT, sans modification des abonnements existants.';
    }

    public function up(Schema $schema): void
    {
        // No Stripe product/price is invented. Those identifiers are configured after Stripe setup.
        $offers = [
            ['SOLO', 'Solo', 'Pour les formateurs indépendants et les petites structures.', 2900, 100, 1, 1, '#f59e0b', null, 1],
            ['PRO', 'Pro', 'Pour développer votre activité avec une petite équipe.', 5900, 300, 3, 5, '#10b981', 'Recommandé', 2],
            ['ORGANISME', 'Organisme', 'Pour coordonner votre organisme et vos équipes.', 9900, 1000, 10, 0, '#8b5cf6', null, 3],
        ];
        foreach ($offers as [$code, $name, $tagline, $price, $trainees, $users, $trainers, $accent, $badge, $order]) {
            $limits = json_encode(['admins' => $users, 'formateurs' => $trainers], JSON_THROW_ON_ERROR);
            $this->addSql('INSERT INTO billing_plan (code, name, tagline, max_apprenants_an, max_utilisateurs, max_formateurs, max_entreprises, max_prospects, support_prioritaire, is_active, price_monthly_cents, price_yearly_cents, accent_color, badge, ordre, seat_limits) SELECT ?, ?, ?, ?, ?, ?, 0, 0, 0, 1, ?, NULL, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM billing_plan WHERE UPPER(code) = ?)', [$code, $name, $tagline, $trainees, $users, $trainers, $price, $accent, $badge, $order, $limits, $code]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les offres peuvent être utilisées par des abonnements. Désactivez-les sans supprimer leur historique.');
    }
}
