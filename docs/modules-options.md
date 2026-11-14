# Options et abonnements par organisme

## Fonctionnement livré

- Boutique : `/fr/organisme/{id}/options`, accessible aux utilisateurs ayant `ENTITE_BILLING_MANAGE`, depuis Comptabilité → Options & modules et la page Abonnement.
- Administration : `/fr/super/options`, réservée au super-administrateur, liée depuis la console plateforme.
- CACES : 200 €/mois, essai initial de 30 jours (durée configurable). Le mois de facturation est celui de Stripe ; l’essai est exprimé en jours.
- Chaque module a son abonnement Stripe indépendant et un client de facturation dédié à l’organisme/module, sur le compte Stripe de la plateforme. Aucun `Stripe-Account`, transfert Connect ou commission d’application n’est envoyé. Les revenus rejoignent également le suivi plateforme.
- Résiliation à tout moment, effective en fin de période payée ou d’essai. Elle peut être annulée avant son effet. Après sa fin, une nouvelle souscription rétablit les accès aux mêmes données. Un nouvel abonnement ne redonne pas l’essai initial.
- Les statuts `incomplete`, `past_due`, `unpaid`, `paused`, `canceled`, `incomplete_expired` ne donnent pas accès. Les périodes expirées ferment l’accès même sans réception d’un webhook.
- Les accès offerts sont indépendants du paiement : temporaires ou sans date de fin, révocables et journalisés avec l’auteur et le motif. Un essai offert dans la console n’engage aucun prélèvement automatique et consomme l’essai initial. Un cadeau n’arrête pas un abonnement payant déjà en cours : l’interface le signale explicitement.
- I.T. For You utilise l’identité interne déjà définie par le projet : organisme ID 1 **et** SIRET `80813612100023`. Renommer un organisme ne suffit jamais à obtenir l’exemption. Tous ses membres conservent leurs contrôles de rôle habituels.
- Une option « incluse » est accessible à tous les organismes autorisés à utiliser sa fonctionnalité. Le catalogue reste distinct des anciens packs d’apprenants (`billing_addon`).

## Mise en service

1. Sauvegarder la base et prévoir une fenêtre de mise en service : le contrôle CACES est actif dès le déploiement du code. Un organisme sans droit sera bloqué, même si l’option reste en brouillon. Préparer les gratuités/essais des organismes existants avant de rouvrir leur accès.
2. Appliquer les migrations, notamment `Version20261114110000`. Cette migration crée seulement le catalogue et les droits : elle ne touche aucun dossier CACES et ne crée aucun objet Stripe. Elle prépare CACES **en brouillon**, pour éviter de commercialiser un tarif non configuré.
3. Dans la console Options, ouvrir CACES, vérifier le montant, cocher « Créer un nouveau tarif Stripe pour ce montant » et « Publier dans la boutique », puis enregistrer. Ou saisir l’ID d’un prix Stripe existant. Le serveur vérifie montant, EUR, périodicité mensuelle, quantité fixe et disponibilité du prix. La clé `STRIPE_SECRET_KEY` existante détermine le compte receveur et le mode test/live ; vérifier qu’elle correspond au compte plateforme I.T. For You.
4. Utiliser le webhook d’abonnements existant `/fr/stripe/webhook` et son `STRIPE_WEBHOOK_SECRET`. Ce n’est pas l’endpoint des factures des organismes `/fr/webhooks/stripe`. Activer au minimum `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.paused`, `customer.subscription.resumed`, `invoice.paid`.
5. Planifier côté serveur `docker compose exec -T app php bin/console app:modules:reconcile --env=prod` toutes les cinq minutes. Ce rapprochement récupère les checkouts terminés et les états d’abonnement si une notification a été manquée. Il s’arrête avec un code non nul en cas d’échec ; surveiller ce code et les réponses HTTP 500 du webhook.
6. Le portail de facturation utilise sa propre configuration Stripe : factures, moyen de paiement, coordonnées et résiliation à l’échéance. Les changements de produit via le portail sont désactivés pour préserver le lien module/tarif. Activer dans Stripe les courriels de fin d’essai et d’échec de paiement selon les préférences commerciales de la plateforme.
7. Valider un cycle complet avec les clés et webhooks Stripe **de test**, puis utiliser les identifiants de prix live au passage en production. Aucun paiement réel n’a été exécuté lors du développement.

Le tarif enregistré correspond au prix unitaire Stripe. Cette livraison ne configure pas Stripe Tax ni les règles fiscales de l’entreprise ; vérifier la fiscalité du compte et le total présenté dans Checkout avant la publication.

## Changer un tarif

Modifier le montant puis créer un nouveau tarif Stripe dans la console. Le catalogue prend le nouveau prix ; les abonnements déjà conclus gardent leur prix enregistré. Les checkouts déjà ouverts conservent leur proposition jusqu’à expiration. La migration forcée des contrats existants vers un nouveau prix n’est volontairement pas exposée : elle nécessite un processus commercial distinct et une gestion d’information des clients.

Dépublier une option arrête les nouvelles souscriptions, sans révoquer les contrats existants. Retirer un accès offert n’interrompt pas un abonnement payé valide.

## Ajouter une fonctionnalité payante

Créer le module dans le catalogue, puis ajouter `#[App\Security\Attribute\RequiresModule('code')]` à **tous** les contrôleurs de cette fonctionnalité. Chaque action doit résoudre un argument `Entite` correspondant à l’organisme réel de la ressource. Conserver les contrôles de rôle et d’appartenance des ressources. Ajouter son lien d’ouverture à la boutique. Créer une fiche commerciale seule ne transforme pas automatiquement une fonctionnalité arbitraire en module protégé.

CACES est déjà couvert dans ses deux contrôleurs, incluant sources de questionnaires, exports, certificats, théorie, pratique et sauvegardes AJAX. Les données métier ne sont jamais supprimées par le système d’abonnement.

## Robustesse et vérification

- Contrainte unique organisme/module et identifiant d’abonnement Stripe unique.
- Transactions avec verrou SQL sur l’organisme pour sérialiser les mutations, y compris les webhooks ; les doubles clics réutilisent un checkout ouvert.
- Clés d’idempotence Stripe pour les créations. Signature vérifiée avant tout traitement. Le webhook relit l’état Stripe courant au lieu d’appliquer un ancien événement ; validation client/organisme/module/prix/quantité avant activation.
- Droits d’administration et CSRF sur chaque opération de gestion ; aucune activation fondée sur l’URL de retour Checkout.
- Journal des états Stripe et des attributions ; rapprochement répétable ; dates d’accès vérifiées sur chaque requête CACES.
- Tests isolés SQLite et faux client HTTP Stripe : accès, expiration, réactivation, résiliation, rejouabilité, isolation, tarifs, compte destinataire et revenus. Il reste à exécuter le cycle Stripe de test et un test de concurrence sur la base MySQL de préproduction ; les tests SQLite ne simulent pas ses verrous.

Commandes de validation :

```sh
docker compose exec -T app php bin/console lint:container --env=test
docker compose exec -T app php bin/console lint:twig templates/modules --env=test
docker compose exec -T -e DATABASE_URL='sqlite:///:memory:' app php bin/console doctrine:schema:validate --skip-sync --env=test
docker compose exec -T app php vendor/bin/phpunit tests/Service/Module tests/Integration/CacesPortalsTest.php tests/Service/Billing/StripeWebhookTest.php tests/Integration/SubscriptionAccessTest.php
```

Références : [cycle des abonnements et webhooks Stripe](https://docs.stripe.com/billing/subscriptions/webhooks), [configuration du portail](https://docs.stripe.com/api/customer_portal/configurations/create).

## État de validation de cette livraison

62 tests ciblés passent (323 assertions), ainsi que le lint Twig, l’injection des services et la validation des mappings Doctrine. Le rendu de la boutique a été inspecté dans Chrome sur ordinateur et à 390 px de largeur ; aucun débordement horizontal à cette largeur. Les modifications ultérieures de la page d’accès suspendu ont été vérifiées par les trois tests fonctionnels du module.

Le 14 novembre 2026, la migration `Version20261114110000` a été appliquée avec succès dans Docker, via le service `app`, à la base MySQL locale du service `database`. Les deux tables et le module CACES ont été vérifiés. Les tests automatisés initiaux ont utilisé une base SQLite isolée. L’environnement du projet est exclusivement Docker ; le chemin historique contenant MAMP ne doit pas être utilisé pour choisir les services ou les exécutables. La suite supplémentaire `PlatformDashboardTest` rencontre un problème préexistant de fixture (`notification_receipt` créée deux fois) ; la suite globale n’est donc pas annoncée comme validée. Les nouveaux tests de revenus module vérifient séparément l’attribution et l’idempotence.
