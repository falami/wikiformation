# Centre de notifications et suivi des dossiers

Le bouton cloche de l’en-tête ouvre les notifications. « Ouvrir le centre de suivi » donne accès aux catégories, à la recherche, aux actions à traiter et aux archives. Les utilisateurs ayant plusieurs rôles disposent d’un sélecteur d’espace. Actualisation toutes les 60 secondes quand la page est visible et à l’ouverture de la cloche.

Les éléments à traiter sont calculés depuis les données actuelles. La lecture et l’archivage sont personnels ; ils ne valident aucun document, ne signent rien et ne modifient pas le dossier. Une nouvelle étape ou un événement ultérieur peut faire apparaître une nouvelle notification.

## Couverture

- Administration : dossiers, pièces déposées/validées, contrats formateurs, signatures horodatées, appréciations, comptes rendus, factures et changements de statut.
- Formateur : sessions affectées, contrats personnels disponibles, présences et retours attendus, pièces des dossiers, comptes rendus et questionnaires affectés.
- Stagiaire : ses inscriptions, pièces manquantes avec accès au dépôt, signatures et appréciations attendues.
- Entreprise : ses employés inscrits dans l’organisme, leurs suivis et les factures adressées à l’entreprise.
- OF : sessions dont l’entreprise associée au compte est l’organisme donneur d’ordre et factures qui lui sont adressées.

Les sessions annulées/finalisées ne génèrent pas de demande d’émargement. La sous-traitance exclut les relances d’émargements et d’appréciations. Les présences papier et absences renseignées sont prises en compte. Les contrats brouillons ne sont pas notifiés au formateur ; un contrat résilié ou archivé n’est pas présenté comme à signer.

## Historique

Le journal est enregistré avec les changements métier, dans la même transaction Doctrine. Les événements sont limités aux métadonnées ; aucun contenu de document, image de signature ou jeton d’accès n’est copié. Les accès sont revérifiés à chaque lecture, par organisme et par rôle. Le centre affiche les 1 000 derniers événements autorisés, plus les états et pièces actuels. Le journal en base n’est pas limité à 1 000 lignes.

L’historique des modifications commence à l’installation. Les dates de signature, dépôt et soumission déjà présentes sont reprises ; les dates inconnues ne sont pas inventées. La suppression d’un dossier ou d’un utilisateur supprime les événements associés par cascade : ce module est un suivi opérationnel, pas une archive probatoire indépendante.

## Installation en production

Déployer les fichiers puis exécuter cette migration avant de rouvrir l’application :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261012090000' --up --no-interaction --env=prod
php bin/console cache:clear --env=prod
```

La migration a déjà été exécutée sur la base locale. Elle crée `notification_event` et `notification_receipt`. Les notifications sont internes à l’application ; cette fonctionnalité n’envoie pas automatiquement d’e-mails supplémentaires.

## Vérifications

Tests d’intégration SQLite : journal transactionnel sans doublon de flush, confidentialité stagiaire/entreprise/organisme, contrats résiliés, présences annulées/finalisées, rendu du centre et flux JSON, CSRF, lecture/archivage/restauration, refus d’une clé inaccessible. Les tests de relances existants sont également exécutés. Vérification visuelle sur navigateur à effectuer après déploiement : le contrôle Chrome n’était pas autorisé dans l’environnement de développement.
