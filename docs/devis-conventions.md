# Devis, conventions et inscriptions

Depuis la fiche ou la liste des devis, **Créer une convention** permet :

1. De choisir une session existante correspondant à la formation du devis, ou de créer une session avec sa formation, son lieu, sa capacité et ses créneaux.
2. De sélectionner un ou plusieurs stagiaires pour un devis d’entreprise. Pour un devis individuel, le destinataire est le stagiaire couvert.
3. De créer la convention, ses inscriptions manquantes et les dossiers d’inscription dans une transaction unique.

Les inscriptions existantes sont réutilisées. Une inscription déjà associée à une autre entreprise n’est pas réattribuée automatiquement. Pour une convention d’entreprise, rattacher au préalable les inscriptions existantes à cette entreprise.

Une session et un devis peuvent avoir plusieurs conventions. Chaque convention contient uniquement ses inscriptions sélectionnées. Les écrans de session, d’inscription et du dossier stagiaire présentent ces liens explicites. Le renvoi du même formulaire de conversion ne crée pas un second dossier.

Le PDF contient la référence et le **total du devis**, dans sa devise. Ce total n’est pas réparti automatiquement entre plusieurs conventions ni imputé intégralement à chaque stagiaire. Les conditions financières permettent de préciser la prise en charge de chaque dossier.

Un devis ayant une convention doit être dupliqué pour préparer une nouvelle proposition. Une convention signée ne peut plus être modifiée ou supprimée. Une nouvelle signature invalide le PDF précédent afin de permettre sa génération avec la signature.

## Base de données

La migration `DoctrineMigrations\Version20260918120000` ajoute le lien facultatif au devis et remplace les deux contraintes d’unicité par session/destinataire par des index ordinaires. Elle ne supprime aucune convention ni inscription. Les conventions existantes gardent leur contenu et leurs inscriptions ; leur devis d’origine n’est pas deviné.

Pour déployer cette seule migration sur une autre installation MySQL/MariaDB :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260918120000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260918120000' --up
```

Le retour arrière automatique est désactivé : les nouvelles conventions multiples ne respecteraient plus les anciennes contraintes.

## Vérifications

```sh
php vendor/bin/phpunit
php bin/console lint:container --env=test
php bin/console doctrine:schema:validate --skip-sync
```

Les tests d’intégration créent une base SQLite en mémoire, sans modifier la base de développement. Ils couvrent la persistance des relations, la réutilisation des inscriptions, les conventions multiples, le formulaire HTTP et son double envoi, le filtrage par organisme, les inscriptions individuelles, les questionnaires associés et le rendu PDF. Les tests métier vérifient aussi la capacité, les annulations, les horaires et les montants du devis.
