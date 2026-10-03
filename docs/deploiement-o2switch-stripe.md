# Mise en service : tarifs mensuels, Stripe et o2switch

Décision du 3 octobre 2026 : Solo **29 €**, Pro **59 €**, Organisme **99 €**, Excellence **150 € HT/mois**. Les nouvelles souscriptions sont mensuelles. Les abonnements annuels déjà souscrits conservent leur contrat ; le retrait du sélecteur annuel ne les résilie pas.

## Stripe

Créer quatre **prix récurrents mensuels**, en **EUR**, avec des **taxes exclusives** (montants HT : 2 900, 5 900, 9 900 et 15 000 centimes). On peut conserver les produits Stripe appropriés et leur ajouter ces nouveaux prix. Un montant déjà créé ne se modifie pas : il faut créer un nouveau prix. Archiver un ancien prix ne migre pas les abonnements existants. [Documentation Stripe](https://docs.stripe.com/products-prices/manage-prices)

Les identifiants `price_...` doivent ensuite être associés au catalogue Wikiformation. Les identifiants de test et de production sont distincts. La procédure et les codes d’offres sont dans [offres-abonnement-2026.md](offres-abonnement-2026.md). Ne pas réutiliser le prix historique à 799 € pour l’offre à 150 €. Le checkout compare le montant, la monnaie et la périodicité avant de démarrer le paiement.

Configurer les clés du bon environnement dans la configuration privée du serveur : `STRIPE_SECRET_KEY`, `STRIPE_PUBLIC_KEY` et `STRIPE_WEBHOOK_SECRET`. Aucune clé ne doit être ajoutée aux templates ni aux fichiers publics.

Dans le **portail client Stripe**, activer la consultation des factures et la gestion du moyen de paiement. Laisser le changement d’offre au parcours Wikiformation : désactiver **Switch plan** dans le portail Stripe pour qu’il ne repropose pas les anciens prix annuels. Les abonnements annuels existants restent consultables. [Réglages du portail Stripe](https://docs.stripe.com/customer-management/configure-portal)

L’intégration utilise déjà `automatic_tax.enabled=true` : compléter Stripe Tax avec l’adresse de l’entreprise, la catégorie du service et les immatriculations fiscales applicables. Garder le comportement des prix **exclusive**. [Configuration Stripe Tax](https://docs.stripe.com/tax/set-up)

Créer une destination webhook HTTPS vers **`https://wikiformation.fr/fr/stripe/webhook`**, avec ces événements utilisés par le code :

- `checkout.session.completed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`

Utiliser le secret de signature de cette destination. La route vérifie la signature ; un échec de synchronisation renvoie désormais HTTP 500 pour permettre une nouvelle livraison par Stripe. Tester un abonnement dans l’environnement de test et vérifier le statut local avant l’ouverture des paiements réels. [Webhooks Stripe](https://docs.stripe.com/webhooks)

## o2switch : réglages à vérifier dans cPanel

1. **Domaines** : la racine du domaine doit pointer vers le dossier `public` du projet. Le stockage `var/storage`, la configuration et les sources restent en dehors de la racine publique.
2. **Sélectionner une version de PHP** : choisir PHP **8.3 minimum**, exigé par les dépendances actuellement installées ; PHP 8.4 est utilisé pour les tests locaux. Vérifier aussi la version du PHP utilisé en ligne de commande.
3. **Extensions PHP** : vérifier notamment `pdo_mysql`, `curl`, `mbstring`, `intl`, `gd`, `fileinfo`, `dom`, `simplexml`, `xml`, `xmlreader`, `xmlwriter`, `zip`, ainsi que les extensions de base. Le contrôle Composer ci-dessous donne la liste exacte des exigences de la version déployée.
4. **HTTPS**, connexion à la base et SMTP fonctionnels. En production : `APP_ENV=prod`, `APP_DEBUG=0`. Les URL générées doivent pointer sur le domaine réel ; l’`APP_URL` utilisée par le retour Stripe doit inclure `/fr` avec la configuration actuelle des routes.
5. Donner à l’utilisateur PHP l’accès en écriture à `var/cache`, `var/log`, `var/storage` et aux répertoires d’uploads existants. Sauvegarder les fichiers privés avec la base.

Ces réglages se font dans les outils PHP et Domaines de cPanel ; aucun service Docker n’est nécessaire sur l’hébergement. [Guide Symfony o2switch](https://faq.o2switch.fr/guides/php/heberger-application-symfony/)

Dans le terminal du serveur, depuis la racine réelle du projet, après déploiement des fichiers et sauvegarde :

```sh
php -v
composer check-platform-reqs --no-dev
php bin/console doctrine:migrations:status --env=prod
php bin/console doctrine:migrations:list --env=prod
```

Examiner les migrations en attente avant de les appliquer. Les procédures des dossiers automatisés et du catalogue sont dans [dossiers-automatises.md](dossiers-automatises.md) et [offres-abonnement-2026.md](offres-abonnement-2026.md). Après migration :

```sh
php bin/console cache:clear --env=prod
php bin/console lint:container --env=prod
php bin/console app:workflows:run --env=prod
```

La dernière commande est une **simulation** : elle n’envoie aucun email. Les fichiers CSS/JS de `public/dist` doivent également être déployés.

## Tâche planifiée pour les dossiers automatisés

Dans **cPanel → Tâches cron**, sélectionner une exécution toutes les cinq minutes : minute `*/5`, les quatre autres champs `*`. Exemple de commande, à adapter au nom du compte et au répertoire du projet :

```sh
cd /home/VOTRE_COMPTE/wikiformation && flock -n /home/VOTRE_COMPTE/.wikiformation-workflows.lock /opt/alt/php83/usr/bin/php bin/console app:workflows:run --execute --env=prod >> var/log/workflows-cron.log 2>&1
```

Le chemin PHP 8.3 est documenté par o2switch. Vérifier `php -v` avec ce binaire ; adapter le chemin si une autre version est utilisée. `flock` évite les exécutions simultanées. Vérifier régulièrement le journal et sa rotation. [Tâches cron o2switch](https://faq.o2switch.fr/cpanel/outils-avances/tache-planifiee-cron/)

Activer cette commande après une recette avec un dossier dédié : seuls les dossiers activés dans Wikiformation sont traités. Il n’y a pas de worker Messenger à ajouter pour ce parcours, qui dispose de sa propre commande.

## Vérification de l’erreur affichée dans l’éditeur

`App\Entity\Session::isEmargementRequis()` existe dans le projet et les tests de périmètre d’émargement passent. Le contrôleur doit être déployé avec la version correspondante de `src/Entity/Session.php`. Si seul l’éditeur signale encore une méthode absente, enregistrer les fichiers puis réindexer Intelephense/recharger la fenêtre. L’avertissement de la capture ne démontre pas une erreur PHP en exécution.

Ces réglages ne sont pas appliqués automatiquement à Stripe ou à l’hébergement par les modifications du code local.
