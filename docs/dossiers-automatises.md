# Dossiers de formation automatisés

## Accès et fonctionnement

Le menu **Session → Dossiers automatisés** et le bouton **Formation automatisée** de la liste des sessions ouvrent le parcours. Un dossier appartient à un organisme, une session et une convention. Une session peut conserver plusieurs conventions pour des entreprises différentes.

Le formulaire prépare la session, ses journées, un devis au prix global du groupe et sa convention. Les noms ne sont pas obligatoires : l’effectif prévisionnel est conservé. Les horaires sont calculés avec 7 heures de cours et 90 minutes de pause par journée ; une durée quotidienne de 4 heures ou moins ne reçoit pas de pause. Le début et la durée restent modifiables. Trois journées standard représentent donc 21 heures.

Le dossier démarre **en pause**, pour vérifier le contact et le calendrier avant les envois. L’administrateur active les actions dans ses réglages. Les nouvelles offres Solo, Pro et Organisme donnent accès à ce parcours dans les limites de leurs quotas.

| Échéance | Traitement |
|---|---|
| J−30 avant le début | Lien privé vers la convention PDF et la demande de signature |
| J−15 | Lien permettant à l’entreprise de compléter les participants |
| J−7 | Import des participants disponibles, invitations des nouveaux comptes, convocations PDF et information des formateurs affectés |
| Jour J | QR code vers l’espace stagiaire, avec connexion et signature personnelle |
| Fin de la dernière journée | Satisfaction, évaluation, attestations et certificats, selon les prérequis ci-dessous |
| Lendemain de la fin | Facture issue du devis, puis email avec le PDF |
| 30 jours après l’émission de la facture | Une relance si un solde subsiste après paiements et avoirs |
| Deux mois avant l’échéance du certificat | Proposition de recyclage si activée et si le client n’a pas refusé les rappels |

Les horaires d’envoi sont calculés en Europe/Paris. Les étapes antérieures à l’activation deviennent exécutables au prochain passage du planificateur. Les messages préparatoires ne partent plus après la fin de la formation. Une session annulée interrompt ses actions restantes.

## Informations à compléter

- **Participants** : le portail accepte noms, prénoms, dates de naissance et emails facultatifs. Les noms sans email restent collectés, sans création de faux comptes. L’import automatique à J−7 est configurable ; un import manuel est également disponible. Les quotas et la capacité sont vérifiés. Un compte déjà présent dans un autre organisme n’est pas rattaché silencieusement.
- **Accès stagiaire** : un nouveau compte reçoit un lien d’invitation personnel, valable sept jours, pour définir son mot de passe. Aucun mot de passe n’est envoyé en clair.
- **Satisfaction** : associer un modèle actif à la formation. Le parcours utilise l’affectation existante ou la crée une seule fois.
- **Évaluation** : choisir explicitement le QCM dans le dossier ou affecter un QCM POST aux stagiaires. Aucun modèle pédagogique n’est choisi arbitrairement. Une affectation existante incompatible exige une vérification.
- **Attestation de fin de formation** : inscription terminée et assiduité renseignée ; la durée tient compte de l’assiduité. La réussite n’est jamais déduite de la seule date de fin.
- **Certificat métier** : déposer le PDF délivré et validé par une personne habilitée, avec sa date de délivrance. Ce dépôt ne fabrique pas un certificat SST. Les versions et empreintes sont conservées. Le recyclage se fonde sur cette date et sur une réussite validée, pas sur une simple attestation de présence.
- **Facture** : convention signée, devis cohérent et en euros. Les remises, TVA et débours sont repris au centime. Une facture déjà créée depuis le devis est réutilisée ; les envois et relances sont aussi dédupliqués par facture.

Les informations manquantes apparaissent dans le calendrier avec l’état **À compléter**. Elles sont réévaluées aux prochains passages. Les réussites restent enregistrées et ne sont pas rejouées.

## Portail et documents privés

Le lien client est propre au dossier, vérifié par HMAC, révocable et valable jusqu’à trente jours après la fin. Le lien de désabonnement des rappels reste utilisable indépendamment. La signature recueille l’identité déclarée, le consentement, l’horodatage et les empreintes des documents ; il s’agit d’une signature simple, pas d’un service de signature qualifiée.

Les conventions signées et certificats sont stockés sous `var/storage`, en dehors de `public`. Sauvegarder ce répertoire avec la base de données et conserver les permissions d’accès de l’application. Les téléchargements vérifient les droits et l’appartenance au dossier. La clé `APP_SECRET` doit rester stable pour les liens déjà transmis.

## Mise en service serveur

Les migrations locales ne déploient pas le site distant et n’installent pas de tâche planifiée. Sur le serveur cible, après sauvegarde et vérification des migrations en attente :

```sh
php bin/console doctrine:migrations:status --env=prod
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261002160000' --up --env=prod --no-interaction
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261002163000' --up --env=prod --no-interaction
php bin/console cache:clear --env=prod
```

N’exécuter chaque migration que si elle n’est pas déjà appliquée. Le catalogue tarifaire possède sa propre migration et sa procédure dans [offres-abonnement-2026.md](offres-abonnement-2026.md).

Vérifier la configuration de la messagerie, des PDF, de `framework.router.default_uri` (actuellement `https://wikiformation.fr`) et du stockage privé. Faire d’abord une recette avec une boîte de test et un dossier dédié. La simulation n’envoie rien et ne crée pas de documents :

```sh
php bin/console app:workflows:run --env=prod --entite=IDENTIFIANT
```

L’exécution réelle exige `--execute`. Prévoir un lancement toutes les cinq minutes sous l’utilisateur de l’application. Exemple crontab à adapter au chemin installé et au binaire PHP :

```cron
*/5 * * * * cd /chemin/wikiformation && /usr/bin/php bin/console app:workflows:run --execute --env=prod >> var/log/workflows-cron.log 2>&1
```

La commande ne traite que les dossiers activés. Elle sort en erreur si une exécution est interrompue ou un envoi incertain, afin que la supervision du serveur puisse le signaler. Une réservation en base précède les emails ; les tâches et les factures empêchent les répétitions lors de passages concurrents.

## Incidents et limites

Un échec de transport peut survenir après l’acceptation d’un email par le prestataire. Les étapes **À vérifier** et les exécutions interrompues ne sont donc jamais relancées automatiquement. Vérifier les journaux de messagerie avant de confirmer l’envoi ou sa reprise dans le dossier. Les traces des tentatives sont conservées.

Un certificat remplacé est conservé avec son historique ; un document déjà envoyé n’est pas renvoyé silencieusement. Les inscriptions et résultats pédagogiques doivent refléter la formation réellement réalisée. Le scan du QR code ouvre l’émargement et ne signe pas à la place du participant.

L’envoi de facture décrit ici est un envoi PDF par email. Il ne constitue pas une connexion à une plateforme agréée de facturation électronique. L’intégration d’un tel prestataire et la mise en service de ses accès restent distinctes.
