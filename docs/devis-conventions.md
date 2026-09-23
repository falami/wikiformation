# Devis, conventions et inscriptions

Depuis la fiche ou la liste des devis, **Créer une convention** permet :

1. De rechercher une session existante de l’organisme par son code ou son intitulé, ou de créer une session avec sa formation, son lieu, sa capacité et ses créneaux. Les sessions de la formation du devis sont distinguées des autres formations ; les sessions annulées ne sont pas proposées.
2. De sélectionner un ou plusieurs stagiaires pour un devis d’entreprise. Pour un devis individuel, le destinataire est le stagiaire couvert.
3. De créer la convention, ses inscriptions manquantes et les dossiers d’inscription dans une transaction unique.

Les inscriptions existantes sont réutilisées. Une inscription déjà associée à une autre entreprise n’est pas réattribuée automatiquement. Pour une convention d’entreprise, rattacher au préalable les inscriptions existantes à cette entreprise.

Si la session choisie porte sur une autre formation que celle du devis, une confirmation supplémentaire est obligatoire. Vérifier le programme et les montants avant de confirmer : ce choix ne modifie ni la formation ni les montants du devis. Cette règle est vérifiée côté serveur, y compris sans JavaScript.

Une session et un devis peuvent avoir plusieurs conventions. Chaque convention contient uniquement ses inscriptions sélectionnées. Les écrans de session, d’inscription et du dossier stagiaire présentent ces liens explicites. Le renvoi du même formulaire de conversion ne crée pas un second dossier.

Le PDF contient la référence et le **total du devis**, dans sa devise. Ce total n’est pas réparti automatiquement entre plusieurs conventions ni imputé intégralement à chaque stagiaire. Les conditions financières permettent de préciser la prise en charge de chaque dossier.

## Intitulé, durée et liste de stagiaires à compléter

La conversion permet de personnaliser l’intitulé et la durée affichés sur la convention (par exemple « H0B0 — indices adaptés » et « 7 heures »). Ces textes ne modifient ni la formation du catalogue, ni son programme, ni les créneaux de la session. Une durée laissée vide reprend automatiquement les heures de formation hors pauses du planning ; le catalogue reste le recours lorsqu’aucun créneau n’est disponible. Les anciennes durées personnalisées sont conservées. Pour revenir au calcul automatique, vider ce champ avant signature.

Pour une convention d’entreprise, il est possible de combiner :

- les clients existants, dont les inscriptions sont créées ou réutilisées ;
- des noms libres, un nom complet par ligne, sans adresse e-mail ;
- un effectif prévisionnel total lorsque certains ou tous les noms ne sont pas encore connus.

Un effectif laissé vide est calculé à partir des clients et des noms saisis. Un total renseigné doit inclure ces personnes. Par exemple, 2 clients sélectionnés + 3 noms libres + un total de 8 produisent une convention de 8 stagiaires, dont 3 restent à désigner. Les noms libres et les places anonymes ne créent aucun compte, aucun faux e-mail et aucune inscription fictive. Le PDF les présente comme une liste à compléter.

Depuis **Modifier la convention**, avant signature, compléter les noms ou rattacher les inscriptions de la session lorsque les fiches clients sont créées. Retirer alors les noms libres correspondants pour éviter de les compter deux fois. Une convention individuelle reste liée au seul destinataire du devis.

La conversion vérifie que l’effectif de ce dossier tient dans la capacité disponible de la session. L’effectif prévisionnel est une mention du document, pas une réservation globale de places entre conventions : plusieurs conventions peuvent concerner les mêmes stagiaires.

Un devis ayant une convention doit être dupliqué pour préparer une nouvelle proposition. Une convention signée ne peut plus être modifiée ou supprimée. Une nouvelle signature invalide le PDF précédent afin de permettre sa génération avec la signature.

## Base de données

La migration `DoctrineMigrations\Version20260918120000` ajoute le lien facultatif au devis et remplace les deux contraintes d’unicité par session/destinataire par des index ordinaires. Elle ne supprime aucune convention ni inscription. Les conventions existantes gardent leur contenu et leurs inscriptions ; leur devis d’origine n’est pas deviné.

Pour déployer cette seule migration sur une autre installation MySQL/MariaDB :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260918120000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260918120000' --up
```

Le retour arrière automatique est désactivé : les nouvelles conventions multiples ne respecteraient plus les anciennes contraintes.

La migration additive `DoctrineMigrations\Version20260919010000` ajoute les quatre colonnes facultatives `intitule_formation`, `duree_formation`, `participants_libres` et `effectif_previsionnel`, sans supprimer ni réécrire les données existantes. Pour une autre installation :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260919010000' --up --dry-run
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20260919010000' --up
```

## Vérifications

```sh
php vendor/bin/phpunit
php bin/console lint:container --env=test
php bin/console doctrine:schema:validate --skip-sync
```

Les tests d’intégration créent une base SQLite en mémoire, sans modifier la base de développement. Ils couvrent la persistance des relations, la réutilisation des inscriptions, les conventions multiples, le formulaire HTTP et son double envoi, le filtrage par organisme, les inscriptions individuelles, les questionnaires associés et le rendu PDF. Les tests métier vérifient aussi la capacité, les annulations, les horaires et les montants du devis.

Le parcours HTTP vers une session existante dispose d’un test de régression : aucun `SessionJour` vide n’est initialisé ni validé dans ce mode. Les autres scénarios couvrent aussi les noms sans e-mail, l’effectif seul, les listes mixtes, les effectifs incohérents, la personnalisation du document et l’ajout ultérieur d’inscriptions.

Les tests couvrent également la recherche parmi les autres formations du même organisme, l’exclusion des sessions annulées et des données d’autres organismes, la confirmation obligatoire en cas de différence de formation, le rattachement explicite d’une ancienne convention et l’affichage du lien sur le devis. Les gardes métier refusent toujours un rattachement signé ou entre organismes, même si la différence de formation est confirmée.

## Retrouver les conventions depuis un devis

La liste des devis affiche désormais **Formation** et **Dates de formation**. Elle utilise les sessions rattachées par convention ou inscription, regroupe les doublons et affiche chaque période lorsque plusieurs sessions sont liées. Les sessions sans dates sont signalées « À planifier » ; un devis sans session est indiqué « Non planifiée ». La recherche porte aussi sur les intitulés de formation, y compris ceux personnalisés sur les conventions.

La fiche devis charge explicitement ses conventions enregistrées, avec leur numéro, session, intitulé personnalisé et effectif. Les créations actuelles conservent ce lien en base. Les conventions anciennes dépourvues de `devis_id` ne sont pas déduites du seul nom du client.

Dans **Conventions du devis → Retrouver une convention déjà créée**, choisir la convention correspondante puis confirmer le rattachement. La recherche propose uniquement les conventions du même organisme et du même destinataire, non signées et sans devis source. Les conventions d’une autre formation sont identifiées séparément et nécessitent une confirmation supplémentaire après vérification. Le serveur revalide ces conditions dans une transaction. Un formulaire falsifié ou une convention devenue signée ne peut pas contourner ces contrôles.

Après rattachement, régénérer le PDF de cette convention non signée pour reprendre la référence et les montants du devis. Aucun document historique n’est associé automatiquement et aucun fichier antérieur n’est supprimé. Une convention signée doit conserver son document original.
