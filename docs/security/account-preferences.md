# Préférences du compte et de l’organisme

Accès : menu de l’avatar → **Mon compte et préférences**, ou roue dentée.

- Coordonnées personnelles : prénom, nom et téléphone. L’adresse de connexion reste modifiable par l’administrateur via la gestion des utilisateurs.
- Sécurité : mot de passe actuel obligatoire, nouveau mot de passe confirmé (12 à 128 caractères), déconnexion après changement. La connexion suivante repasse par le code e-mail.
- Signature personnelle : dessin ou import PNG/JPEG, aperçu, remplacement et suppression. Stockage dans `personal_signature`, séparé de la signature de l’organisme. L’utilisateur connecté est toujours le propriétaire ; aucun identifiant utilisateur n’est accepté dans la requête. Ce modèle ne signe pas automatiquement un document.
- Contrats et organisme : réservé à `ENTITE_PREFERENCE_MANAGE`. Signature et tampon existants conservés, clauses regroupées en rubriques. Les sauvegardes d’images exigent désormais un jeton CSRF.

## Déploiement

Déployer les fichiers PHP, Twig, CSS et JS ainsi que la migration `Version20261005170000`. L’extension PHP GD doit être disponible pour décoder et réencoder les signatures importées. Aucun nouveau paquet Composer.

Après sauvegarde habituelle de la base et revue des migrations en attente :

```sh
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
php bin/console cache:clear --env=prod --no-debug
```

La migration crée uniquement la table de signatures personnelles ; les préférences et signatures existantes de l’organisme restent inchangées.

## Vérification

`php bin/phpunit tests/Integration/AccountPreferencesTest.php tests/Integration/EmailTwoFactorLoginTest.php`

Tests sur SQLite en mémoire : droits utilisateur/administrateur, coordonnées, mot de passe actuel, déconnexion, CSRF, import invalide, isolation des signatures, suppression et parcours de double vérification.
