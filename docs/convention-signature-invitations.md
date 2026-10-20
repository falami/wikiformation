# Invitations à signer une convention

Depuis l’espace entreprise, le bouton « Inviter un signataire » demande le nom, la fonction et l’adresse e-mail du représentant habilité. L’e-mail contient un lien et un QR code généré localement. Aucun compte n’est créé pour le destinataire.

L’accès est limité à une convention, à son entreprise et à l’organisme d’origine. Il expire après 48 heures. Une nouvelle invitation révoque les précédentes ; un délai minimum d’une minute limite les doubles envois. Une convention modifiée nécessite une nouvelle invitation. Les jetons aléatoires de 256 bits sont stockés sous forme d’empreinte SHA-256.

Le signataire consulte le PDF, trace ou importe sa signature PNG et confirme son habilitation et son accord. La transaction de signature verrouille le document et l’invitation, archive l’original, enregistre le PDF signé et consomme l’invitation. Les autres liens éventuels sont également refusés dès que la convention est signée. Les pages et PDF temporaires sont servis sans cache et sans transmission de référent.

L’historique conserve le nom, la fonction et l’e-mail déclarés, l’auteur de l’invitation, sa référence, l’heure, l’adresse IP, l’agent utilisateur et les empreintes du document source et signé. L’accès par lien personnel n’est pas une vérification d’identité indépendante.

## Déploiement

Après déploiement du code, appliquer la migration avec le PHP du serveur depuis la racine du projet :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261020090000' --up --env=prod --no-interaction
php bin/console cache:clear --env=prod
```

L’envoi utilise la configuration de messagerie existante de l’organisme. Les tests d’intégration utilisent une base SQLite éphémère et un transport simulé, sans envoi réel.
