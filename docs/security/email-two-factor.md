# Connexion avec vérification par e-mail

Le mot de passe est vérifié par Symfony, puis Scheb TwoFactorBundle maintient un token sans les droits du compte jusqu’à validation du code. Le mécanisme s’applique à tous les utilisateurs de la plateforme, y compris les administrateurs. Les sessions déjà ouvertes continuent jusqu’à leur déconnexion/expiration ; toute nouvelle connexion exige le code.

- Code aléatoire à six chiffres, valable dix minutes, consommé à la première validation.
- Empreinte HMAC avec secret applicatif et nonce ; aucun code en clair dans la session.
- Challenge lié à la session, à l’identifiant, à l’adresse e-mail et à l’empreinte du mot de passe. Durée maximale de la demande : trente minutes avant de recommencer avec le mot de passe.
- Cinq essais par code ; dix vérifications par compte en quinze minutes, y compris depuis d’autres sessions.
- Renvoi après soixante secondes ; cinq envois par compte en quinze minutes. Un renvoi remplace le code précédent. Les rechargements de page ne déclenchent pas de nouvel envoi.
- Protection CSRF du mot de passe, de la vérification et du renvoi. Rotation de session assurée par Symfony.
- Limitation Symfony des essais de mot de passe (cinq par minute, avec limites utilisateur/IP et IP).
- Reconnexion automatique « remember me » désactivée. Aucun appareil exempté de la vérification.
- En cas d’échec SMTP, aucun accès accordé ; message permettant de retenter l’envoi. Les journaux applicatifs de cet échec ne contiennent ni le code ni le message SMTP.

## Déploiement

Installer les dépendances de `composer.lock` avec la commande habituelle (`composer install --no-dev --optimize-autoloader`), puis vider/réchauffer le cache de production. Aucune migration SQL et aucun nouveau paramètre secret ne sont nécessaires. Le mécanisme utilise `APP_SECRET` et le transport `MAILER_DSN` existants. L’expéditeur est `no-reply@wikiformation.fr`, comme pour la réinitialisation du mot de passe.

Vérifier la réception réelle du message sur une boîte contrôlée avant la mise en service en production. Les tests automatiques interceptent les e-mails et n’envoient rien aux utilisateurs. La production doit fonctionner avec HTTPS et le mode debug désactivé, afin que le profiler ne collecte pas les codes ou les e-mails.

Le déploiement actuel utilise les sessions PHP natives, le cache de limitation et les verrous `flock` sur un même hôte. Pour plusieurs serveurs, configurer des sessions partagées avec verrouillage et un cache/stockage de verrouillage partagé (par exemple Redis) afin de conserver la consommation atomique et les limites globales.

Le lien personnel par QR code destiné aux participants sans compte demeure un parcours distinct ; cette double authentification concerne la connexion aux comptes.

## Vérification

`php bin/phpunit tests/Unit/EmailChallengeTest.php`

`php bin/phpunit tests/Integration/EmailTwoFactorLoginTest.php`


## Durée de connexion

Le cookie de session et la conservation des sessions côté serveur sont configurés pour 30 jours (2 592 000 secondes). Les fichiers sont isolés dans `var/sessions/<environnement>`, hors du cache Symfony et du répertoire temporaire partagé de PHP. Le nettoyage probabiliste PHP est activé (1 % des requêtes) avec cette même durée de conservation. La vérification e-mail reste obligatoire lors d’une nouvelle connexion ; aucun mécanisme remember-me ne contourne la double vérification.

En production, conserver `var/sessions/prod` entre les déploiements et le rendre accessible en écriture au processus PHP. Avec des répertoires de versions distincts, utiliser un répertoire partagé persistant via un lien symbolique. Ne pas purger ce dossier lors du nettoyage du cache et ne pas lui appliquer un nettoyage système plus court. Un hébergement sur plusieurs serveurs nécessite un stockage de sessions partagé avec verrouillage.

Le changement de répertoire de sessions demandera une reconnexion aux utilisateurs déjà connectés au moment de cette mise à jour. Les nouvelles connexions utilisent ensuite la nouvelle durée. Une déconnexion volontaire, la suppression des cookies ou un changement du mot de passe peuvent toujours mettre fin à la connexion. Aucun paquet ni migration SQL supplémentaire : déployer la configuration et vider le cache de production.
