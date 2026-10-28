# Pilotage plateforme

Accessible aux super administrateurs dans **Administration plateforme → Pilotage plateforme** (`/{locale}/super/{entite}/pilotage`). Les données couvrent toutes les entités ; le paramètre de route conserve le contexte de navigation et ne limite pas ce tableau global.

## Mise en service

Appliquer les migrations `Version20261028230000` et `Version20261028230100` avant de déployer le webhook et le tableau de bord. Elles créent le journal de recettes et les charges. Elles sont déjà appliquées sur la base locale de développement.

Le bouton **Rapprocher Stripe** importe les factures d'abonnement payées pour les clients Stripe connus et leurs frais de transaction. Il ne crée aucun paiement. Pour un historique important, utiliser `php bin/console app:platform:revenue-sync` en ligne de commande. L'identifiant de facture rend les imports rejouables sans doublons. Les nouveaux événements `invoice.paid` alimentent le journal ; refaire le rapprochement pour actualiser remboursements et frais.

Saisir les factures fournisseurs (SuperPDP, hébergement, logiciels, acquisition, autres) dans le formulaire des charges. Une charge peut être commune ou affectée à une entité et une activité. L'archivage la retire des calculs sans effacer son enregistrement. Les estimations sont affichées séparément des charges réelles.

## Lecture des chiffres

- Recettes d'abonnement : encaissements Stripe en EUR et en production, nets des remboursements rapprochés. Les paiements historiques ne sont visibles qu'après import.
- Formations : ventes directes de la plateforme ou commissions des vendeurs ; le volume vendeur n'est pas ajouté aux revenus de la plateforme. Les litiges sont exclus des recettes.
- Paiements des factures : frais de service associés aux paiements enregistrés, et non le montant total des factures des organismes.
- SuperPDP : consommation et allocations internes sont prévisionnelles, distinctes des factures fournisseur saisies. La consommation à facturer n'est pas ajoutée aux encaissements.
- HT et frais inconnus restent signalés comme incomplets ; aucun taux de TVA ou tarif Stripe n'est inventé.
- Contribution : recettes TTC moins frais Stripe connus, charges réelles TTC et commissions partenaires. C'est un indicateur partiel de trésorerie, pas un résultat comptable. Les dates utilisées sont celles des paiements et des charges ; les remboursements rapprochés corrigent la recette d'origine.
- Les charges communes ne sont pas réparties arbitrairement entre entités ou activités.
- Le MRR est une estimation au prix catalogue des abonnements actuellement actifs, hors remises et options. Les comptes et les plans décrivent la situation actuelle, pas une photographie historique mensuelle.
- L'origine provient des références partenaires enregistrées ; à défaut, « Origine non enregistrée » est affiché. Les nouvelles entités sont visibles dans le tableau, sans envoi de notification externe.

Filtres : période de 1 à 36 mois, entité et activité. L'export CSV contient toutes les recettes filtrées, avec protection contre les formules de tableur. Les tableaux larges défilent horizontalement sur petit écran.

## Vérifications

`php bin/phpunit tests/Integration/PlatformDashboardTest.php tests/Service/Billing/StripeWebhookTest.php`

Les tests couvrent les montants incomplets, filtres, devises, doublons Stripe, remboursements, création/archivage des charges, export et restrictions d'accès.
