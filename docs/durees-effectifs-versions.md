# Durées, effectifs et versions des contrats

## Calcul des heures de formation

Le planning reste la source des horaires. Une nouvelle journée prévoit **7 heures de cours et 90 minutes de pause déjeuner** : 08:30–17:00. Un début avancé à 08:00 propose une fin à 16:30 ; un début à 09:00 propose 17:30. Trois journées identiques donnent **21 h**. La fin reste modifiable pour organiser exceptionnellement davantage d’heures de cours.

Le calcul déduit exactement **90 minutes**, jamais une pause artificiellement allongée pour plafonner la durée à 7 h. Ainsi, une plage volontairement prolongée de 08:00 à 17:30 donne 8 h de cours et 90 min de pause. En mode automatique, la pause concerne les créneaux d’au moins 6 heures qui traversent 13:00. Une demi-journée 08:30–12:00 reste à **3,5 h** ; deux demi-journées distinctes sont additionnées sans seconde déduction de déjeuner. Un créneau de soirée ne traversant pas le déjeuner ne déduit pas de pause automatique.

Les anciens créneaux regroupant plusieurs dates avec une heure de fin postérieure à l’heure de début sont interprétés comme la même plage de travail répétée chaque jour, sans compter les nuits. Les créneaux de nuit, dont l’heure de fin précède celle du début, restent continus. Les horaires déjà enregistrés ne sont pas réécrits automatiquement.

Le champ **Pause (minutes)** reste facultatif. Vide, il active ce calcul ; `0` conserve toute l’amplitude horaire ; une autre valeur déduit la pause réelle. Par exemple, 60 minutes de pause sur 08:30–17:00 donnent 7,5 h. Le formulaire affiche immédiatement la durée obtenue. Une pause négative ou supérieure ou égale à l’amplitude est refusée.

Ce calcul est partagé entre la session, le planning des formateurs, les montants proposés selon les heures d’intervention, les conventions sans durée personnalisée, les lettres de mission, convocations, attestations sans durée manuelle et le calcul des heures du BPF. Les heures réellement attestées peuvent rester renseignées manuellement, y compris avec des décimales. Les documents déjà signés restent inchangés.

## Effectif sur la lettre de mission

Pour chaque convention de la session, l’effectif prévisionnel positif est prioritaire. Sinon, les inscriptions actives et les noms libres renseignés sont comptés. Les conventions sont additionnées en dédoublonnant les stagiaires identifiés communs ; les places anonymes ne permettent pas de déduire une identité commune. Sans effectif exploitable dans les conventions, le nombre d’inscrits actifs de la session est utilisé. Ajouter les noms correspondant à un effectif déjà déclaré n’augmente donc pas le total.

## Migrations MySQL / MariaDB

Sur l’environnement MAMP local, ces trois migrations ont été appliquées et les entités concernées relues avec succès le 23 septembre 2026. Le site distant n’a pas été déployé.

Sur une autre installation, sauvegarder la base puis exécuter les migrations manquantes avec la version PHP utilisée par l’application :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260922120000' 'DoctrineMigrations\Version20260922120100' 'DoctrineMigrations\Version20260923120000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260922120000' 'DoctrineMigrations\Version20260922120100' 'DoctrineMigrations\Version20260923120000' --up
php -d memory_limit=512M bin/console cache:clear --env=prod
```

- `20260922120000` ajoute `session_jour.pause_minutes`, nullable pour le mode automatique, sans réécrire les créneaux existants.
- `20260922120100` permet les heures décimales dans les attestations.
- `20260923120000` ajoute les numéros de version, le verrou de concurrence et la table des archives des contrats. Les contrats existants démarrent à la version 1.

Le message SQL « Unknown column … pause_minutes » signifie que le code a été mis à jour avant l’exécution de la première migration sur la base utilisée. Déployer code et migrations ensemble. Les migrations ne disposent pas d’un retour arrière destructif afin de conserver les pauses, les fractions d’heures et l’historique.

Le parcours de révision est décrit dans [Formateurs, planning et contrats](formateurs-planning-contrats.md). Inclure `var/storage/contrat-formateur-versions` dans les sauvegardes et dans les dossiers persistants des déploiements.
