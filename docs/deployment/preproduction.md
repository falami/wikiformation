# Première installation de test.wikiformation.fr

La racine web doit être `/home/vibe1331/apps/testwikiformation/public`. Le
fichier `.env.prod.local` appartient exclusivement à cette installation. Ne pas
copier les données, uploads, fichiers `.env.local.php` ou clés de production.

## Configuration minimale complémentaire

Les variables suivantes doivent exister dans `.env.prod.local`. Conserver toute
clé de test déjà configurée ; ne pas remplacer le fichier entier. Les valeurs
vides désactivent les intégrations ou les rendent indisponibles : elles ne les
simulent pas. Le reCAPTCHA des inscriptions publiques nécessitera ses propres
clés pour ce domaine ; le compte initial vérifié n'utilise pas l'inscription.

```dotenv
STRIPE_SECRET_KEY=""
STRIPE_PUBLIC_KEY=""
STRIPE_WEBHOOK_SECRET=""
FULLCALENDAR_SCHEDULER_KEY=""
GOOGLE_MAPS_SERVER_KEY=""
GOOGLE_MAPS_BROWSER_KEY=""
KM_RATE_EUR=0
GCP_PROJECT_ID=""
GOOGLE_APPLICATION_CREDENTIALS=""
GCP_GCS_BUCKET=""
INSEE_CLIENT_ID=""
INSEE_CLIENT_SECRET=""
RECAPTCHA3_KEY=""
RECAPTCHA3_SECRET=""
```

Conserver APP_ENV=prod, APP_DEBUG=0, APP_URL=https://test.wikiformation.fr,
APP_BASE_DOMAIN=test.wikiformation.fr, un APP_SECRET distinct, MAILER_DSN=null://null
et la DATABASE_URL de la base et de l'utilisateur vibe1331_testwikiformation.

## Initialisation

Publier les nouveaux fichiers du projet dans Git puis récupérer cette version
sur le serveur. Ne pas utiliser deploy.sh tant que la base n'est pas initialisée.

```bash
cd "$HOME/apps/testwikiformation" &&
git pull --ff-only &&
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-scripts &&
APP_ENV=prod APP_DEBUG=0 php scripts/check-preproduction.php &&
php bin/console app:preproduction:initialize --env=prod --no-debug
```

La dernière commande simule la création sans écrire. Après succès :

```bash
cd "$HOME/apps/testwikiformation" &&
php bin/console app:preproduction:initialize --execute --env=prod --no-debug
```

Saisir l'e-mail de connexion et un mot de passe distinct (saisie masquée, avec
confirmation). Le compte vérifié dispose des droits super-administrateur de cette
installation uniquement. Créer ensuite des comptes individuels pour les autres
testeurs avec les rôles nécessaires. Le transport mail null ne livre pas leurs
invitations : prévoir une boîte de capture ou un transport restreint pour les tester.

L'initialiseur refuse toute base non vide ou toute configuration hors de la cible
prévue. Il ne supprime rien. MySQL valide les créations de tables indépendamment
des transactions : en cas d'échec, conserver l'état pour diagnostic, ne pas tenter
une suppression automatique ou relancer le déploiement.

## Schéma initial et historique

Les migrations initiales historiques sont des placeholders : elles ne permettent
pas de reconstruire le schéma depuis zéro. Cette commande crée le modèle Doctrine
actuel et marque les versions **du manifeste vérifié** comme couvertes par cette
base initiale. Toute modification des entités ou des migrations bloque cette
initialisation jusqu'à réexamen du manifeste. Les prochaines migrations restent
exécutées normalement par deploy.sh.

Audit du 2026-11-03 : les migrations de données ajoutent les quatre offres publiques
ou transforment des enregistrements existants. Les quatre offres sont recréées sans
identifiants Stripe ; les transformations sur les utilisateurs, inscriptions et
abonnements existants sont sans objet sur une base neuve. Une offre SANDBOX locale
à 0, un organisme privé et un abonnement local actif sans Stripe servent aux tests.
Ce n'est pas une copie du schéma historique et des données de production.

## Finalisation et mises à jour

Après initialisation réussie :

```bash
cd "$HOME/apps/testwikiformation" &&
APP_ENV=prod APP_DEBUG=0 composer run-script post-install-cmd &&
php bin/console cache:clear --env=prod --no-debug &&
php bin/console asset-map:compile --env=prod --no-debug
```

Vérifier HTTPS et la connexion au compte sur le domaine de test. Les services
externes non configurés (paiement, cartes, OCR, facturation électronique) ne sont
pas opérationnels. Ne pas reprendre les tâches planifiées de production.

Pour les mises à jour suivantes, après validation initiale :

```bash
DEPLOY_APP_DIR="$HOME/apps/testwikiformation" \
DEPLOY_BACKUP_DIR="$HOME/backups/testwikiformation" \
bash "$HOME/apps/testwikiformation/deploy.sh"
```

Ce déploiement conserve les comptes et les données fictives. Ne jamais relancer
l'initialisation pour une mise à jour. Tester d'abord en préproduction puis déployer
la même révision en production après validation.
