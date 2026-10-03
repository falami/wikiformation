# Offres d’abonnement

Le catalogue proposé aux nouveaux abonnés est exclusivement mensuel :

| Offre | Mensuel HT | Apprenants par an | Gestionnaires | Formateurs | Code |
|---|---:|---:|---:|---:|---|
| Solo | 29 € | 100 | 1 | 1 | SOLO |
| Pro | 59 € | 300 | 3 | 5 | PRO |
| Organisme | 99 € | 1 000 | 10 | Illimités | ORGANISME |
| Excellence | 150 € | Illimités | Illimités | Illimités | EXCELLENCE_2026 |

Entreprises et prospects illimités. Les capacités sont configurables dans `billing_plan` et les règles de quotas existantes restent utilisées. Toutes les offres comprennent les fonctions administratives communes ; Excellence comprend le support prioritaire.

Accueil et Tarifs partagent le même composant. Les prix viennent de la base, sans appel Stripe pour consulter le catalogue. L’inscription et le changement d’offre ne proposent plus l’annuel, même si un ancien identifiant annuel existe ; les requêtes directes annuelles sont également refusées. Les anciens abonnements annuels et leur prix restent intégralement conservés. Un changement demandé vers une nouvelle offre sera mensuel et soumis à confirmation du prorata.

`EXCELLENCE_2026` est une nouvelle version commerciale à 150 €. L’ancien code `EXCELLENCE`, son prix et ses identifiants Stripe ne sont ni écrasés ni réutilisés. Les autres anciennes offres restent en base pour préserver les abonnés existants, mais ne sont plus proposées à la souscription.

## Activation du paiement

Les migrations n’appellent pas Stripe et n’inventent aucun `price_...`. Le catalogue et les essais sans carte bancaire sont utilisables immédiatement. Un essai en cours reste accessible depuis les tarifs. Une souscription payante reste indisponible tant que son véritable tarif Stripe n’est pas configuré.

1. Dans le compte Stripe de Wikiformation, préparer un tarif récurrent **EUR HT, taxes exclusives, mensuel** par offre : 2 900, 5 900, 9 900 et 15 000 centimes. Utiliser l’environnement test pour la recette.
2. Associer les identifiants réels au catalogue local. Les valeurs ci-dessous sont des exemples à remplacer :

```sh
php bin/console app:billing:configure-price SOLO month price_VOTREIDENTIFIANT --dry-run
php bin/console app:billing:configure-price SOLO month price_VOTREIDENTIFIANT
php bin/console app:billing:configure-price EXCELLENCE_2026 month price_NOUVEAUTARIF150
```

La commande ne modifie aucun abonnement, refuse les anciennes offres et les périodicités annuelles, et empêche la réutilisation d’un identifiant déjà associé à une autre offre. Ne pas affecter le prix Stripe de l’ancienne Excellence à la nouvelle. Le checkout et le changement d’offre vérifient le montant réel Stripe, EUR, la récurrence mensuelle, l’état actif et les taxes exclusives avant toute opération de paiement ; 799 € ne peut pas être facturé pour une offre annoncée à 150 €.

Valider un parcours Stripe test et les webhooks avant ouverture en production. Aucun tarif ni abonnement Stripe n’est créé automatiquement par ces commandes.

## Migrations

`Version20261002170000` ajoute Solo, Pro et Organisme. `Version20261003110000` ajoute la nouvelle Excellence. Toutes deux sont idempotentes et ne remplacent pas une offre déjà présente :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261002170000' --up --no-interaction
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261003110000' --up --no-interaction
```

Les abonnements actuels ne changent que sur demande de leur titulaire. Les secrets Stripe restent dans la configuration d’environnement, jamais dans le chat ou le dépôt.
