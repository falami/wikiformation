# Évaluations théorie et pratique

Ce parcours est accessible dans **Questionnaires → Théorie & pratique**. Les questionnaires de niveau début/fin restent dans **Niveau** avec leur comportement actuel. Aucun questionnaire ou affectation existant n’est converti automatiquement.

## Mise en production

Déployer les fichiers de cette modification, puis appliquer la migration additive `DoctrineMigrations\Version20261101143000`. Elle crée uniquement les tables `evaluation_template` et `evaluation_dossier`, avec leurs index et relations. Elle ne modifie aucune table QCM.

Depuis la racine de l’application sur le serveur :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261101143000' --up --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:clear --env=prod
```

La migration s’exécute une seule fois. L’éditeur utilise Asset Mapper (`assets/js/evaluation-template.js`) ; la compilation des assets est nécessaire en production. Aucune migration n’a été exécutée sur une base réelle pendant le développement.

## Utilisation

1. Dans **Théorie & pratique**, créer un modèle vide, démarrer avec **Travail en hauteur**, ou copier un QCM de niveau existant. La copie ne modifie pas le QCM d’origine.
2. Définir les questions théoriques, le corrigé et les points. Types disponibles : choix unique, choix multiples, classement et réponse libre.
3. Définir les critères pratiques avec points, option « Non applicable » et éventuellement caractère éliminatoire.
4. Renseigner si souhaité les seuils théorie/pratique sur 20. Sans seuil, l’avis explicite du formateur décide. Avec seuil, un avis favorable est refusé si la note est inférieure. Les deux avis restent obligatoires.
5. Après vérification du contenu, cocher l’activation et enregistrer le modèle.
6. **Affecter une évaluation** : choisir la session, les stagiaires et le formateur évaluateur. Celui-ci doit intervenir sur la session et avoir un accès formateur actif dans l’organisme. L’affectation peut concerner plusieurs stagiaires.
7. Le stagiaire retrouve son test dans **Mes évaluations finales**. Une soumission est définitive ; le résultat reste masqué pendant la correction.
8. Le formateur utilise **Évaluer mes stagiaires** pour corriger la théorie, noter la pratique et enregistrer des brouillons. Après avoir donné ses deux avis et confirmé son évaluation, il publie les résultats.
9. Le stagiaire accède alors aux notes, aux observations et au bilan PDF. Un mail l’en informe. L’attestation de réussite est disponible uniquement avec deux avis favorables.

Les affectations de ce nouveau parcours sont explicites : ajouter une inscription ultérieure à la session ne crée pas automatiquement de dossier. Le choix de ce parcours n’ajoute ni ne retire les modèles PRE/POST configurés sur une formation.

## Barèmes et historique

- Les notes sont conservées dans leur barème d’origine et présentées sur 20.
- Une question à choix ou à classer reçoit une proposition de correction tout-ou-rien. Le formateur peut ajuster la note dans la limite du barème avant publication. Une réponse libre nécessite sa notation.
- Un critère non applicable est retiré du dénominateur. Au moins un critère pratique doit être évalué.
- Un critère marqué éliminatoire et « non maîtrisé » empêche l’avis pratique favorable.
- Le modèle Travail en hauteur transcrit les documents fournis (15 questions/20 points et 8 critères/20 points). Il est initialement inactif, sans seuil imposé, et doit être vérifié par l’organisme.
- Le modèle est figé à l’affectation. Modifier sa bibliothèque ne change jamais les évaluations déjà affectées.
- Le stagiaire et la session sont figés dans le dossier, même si l’inscription d’origine est ensuite modifiée.
- Après publication, notes, avis et identité du validateur sont immuables. La validation est nominative et horodatée ; elle ne prétend pas constituer une signature électronique qualifiée.
- Une nouvelle tentative explicite conserve l’ancien dossier et son modèle. Elle est refusée si l’inscription d’origine a changé de stagiaire ou de session.
- Un remplacement d’évaluateur est possible avant toute notation. Une évaluation déjà publiée conserve son validateur.

## Notifications et contrôle des accès

Le mail est envoyé après l’enregistrement des résultats, via le service de messagerie et l’historique des mails existants. Un échec d’envoi ne supprime pas les résultats. Le formateur et l’administrateur peuvent relancer la notification depuis le dossier ; un envoi déjà confirmé n’est pas répété.

Administrateur, évaluateur affecté et stagiaire concerné ont des accès distincts, limités à l’organisme. Ni le corrigé ni les notes privées ne sont rendus dans les pages stagiaire avant publication. Les PDF exigent une authentification et ne sont pas enregistrés dans un dossier public.

## Validation locale

Tests sur SQLite en mémoire et messagerie simulée :

```sh
php vendor/bin/phpunit tests/Service/Evaluation tests/Integration/EvaluationAdminTest.php tests/Integration/EvaluationPortalsTest.php tests/Integration/EvaluationJourneyTest.php tests/Integration/QcmFormationAssignmentTest.php
```

Couverture : modèles et versions, affectations groupées, permissions, copies de QCM, soumission, correction, seuils, N/A, publication, confidentialité, conflits, nouvelles tentatives, mail et PDF. Les tests des affectations de niveau existantes font partie de cette vérification.
