# QR codes individuels des participants

## Utilisation

Dans le tableau de bord du formateur, ouvrir **QR codes participants** sur une session. La page comprend les stagiaires inscrits et les noms saisis dans le champ **Stagiaires sans compte ou sans e-mail** de toutes les conventions de la session. La recherche et le bouton **Imprimer** permettent de remettre à chacun ses deux codes personnels.

- **Émargement** : aucune connexion ; signature pour une demi-journée réellement planifiée aujourd’hui, avec confirmation de présence. Une signature enregistrée ne peut pas être remplacée depuis ce lien.
- **Appréciation** : questionnaire affecté au stagiaire, ou modèle de sa formation. À défaut, un questionnaire standard est créé pour l’organisme. Une réponse envoyée ne peut pas être modifiée depuis ce lien.
- **Espace stagiaire** : ses propres QR codes apparaissent dans le récapitulatif de sa prochaine session et dans le menu de ses sessions.
- **Administration** : accès depuis la fiche session ; les signatures et appréciations des invités sont visibles dans les suivis et exports existants.

Aucun compte ni adresse e-mail fictive n’est créé. Les noms restent liés à leur convention. Un nom retiré ou une inscription annulée/absente ne donne plus accès au parcours public. Les preuves déjà enregistrées sont conservées. Les sessions annulées n’ouvrent aucun accès ; les sessions en sous-traitance ne proposent pas de signature en ligne, conformément au suivi géré par le donneur d’ordre.

Les liens sont nominatifs et doivent être remis seulement à leur destinataire. Ils expirent 90 jours après la fin de la session. Le même QR d’émargement sert aux différentes journées ; seule la date du jour est signable.

## Déploiement

Installer les fichiers de cette fonctionnalité puis les migrations suivantes, dans cet ordre :

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261003140000' 'DoctrineMigrations\Version20261003140500' --up --no-interaction --env=prod
php bin/console cache:clear --env=prod
```

Ces migrations ajoutent les accès participants, leurs relations aux signatures/réponses et la clé du questionnaire standard. Elles ne suppriment pas de données. Elles ont été appliquées à la base Docker locale, mais pas au serveur de production.

Le serveur doit disposer de PHP GD pour contrôler les signatures PNG. Conserver `APP_SECRET` stable entre les déploiements et les serveurs : il signe les accès individuels. Les QR sont générés dans le navigateur avec la bibliothèque locale ; aucun prestataire de QR n’est utilisé.

En production, ouvrir la page depuis l’adresse HTTPS publique habituelle : c’est cette adresse qui est incluse dans les QR. Un QR généré depuis `localhost` n’est pas accessible sur le téléphone d’un stagiaire.
