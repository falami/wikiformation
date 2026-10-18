# Habilitations électriques

## Mise en service

Aucune dépendance Composer supplémentaire : le module utilise Doctrine, Twig, Dompdf et le traitement d’images déjà présents.

Après déploiement du code et des fichiers public/dist :
```sh
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=prod php bin/console cache:clear
```

La migration Version20261006120000 ajoute trois tables et le modèle d’habilitation de la formation. Elle a été exécutée sur la base locale de développement. Elle ne supprime aucune donnée existante. Son retour arrière est volontairement interdit pour préserver les documents signés.

## Configuration par l’administrateur

Dans **Habilitations électriques → Modèles**, créer un modèle à partir de la trame proposée, puis adapter sections, sous-sections et colonnes. Chaque colonne contient soit des choix multiples définis dans le modèle, soit un champ libre. Les éléments peuvent être ajoutés, supprimés et réordonnés.

Dans l’édition d’une formation, sélectionner le **questionnaire d’habilitation**. Les inscriptions reçoivent automatiquement un dossier dès le premier jour de session si l’entreprise et le formateur sont identifiables, y compris pour les sessions passées. Les inscriptions sans affectation complète sont gérées par l’administrateur avec **Affecter une évaluation**.

L’administrateur peut affecter ou réaffecter un dossier à une inscription, un modèle, une entreprise et plusieurs formateurs de son organisme. Une inscription possède un seul dossier ; restaurer ou réaffecter celui-ci au lieu de créer un doublon.

Chaque dossier conserve une copie de son modèle. Modifier un modèle ne change pas rétroactivement les évaluations existantes. Un modèle utilisé est désactivé au lieu d’être supprimé.

## Parcours

1. **Formateur affecté** : saisir l’avis par sous-section, les symboles, domaines de tension, ouvrages, recommandations, dates et qualité du signataire ; enregistrer un brouillon ou signer explicitement.
2. **Administrateur** : contrôler l’avis signé et le transmettre à l’espace entreprise.
3. **Entreprise** : consulter l’avis signé, retenir les habilitations parmi les avis favorables du formateur, préciser fonction, affectation, délivrance et fin de validité, puis signer le titre.
4. **Stagiaire** : après publication par l’entreprise, consulter son dossier et télécharger les deux PDF.

La transmission se fait par la mise à disposition dans l’espace concerné ; aucun email automatique n’est envoyé. L’accès entreprise suit le compte représentant de l’entreprise principale, avec le rôle entreprise dans l’organisme.

L’avis post-formation et le titre de l’employeur sont distincts. L’avis ne vaut pas habilitation. Le modèle fourni ne couvre pas les travaux sous tension. Le PDF du titre prévoit une ligne de prise de connaissance et de signature manuscrite du titulaire après remise.

## Modifications, historique et signatures

L’administrateur peut éditer les données mais ne signe pas à la place d’un tiers. La signature demande l’action explicite du formateur affecté ou du représentant de l’employeur, avec une signature dessinée ou une image PNG/JPEG.

Modifier l’avis retire les deux validations en cours. Modifier le titre retire uniquement sa validation. Réaffecter le dossier réinitialise l’évaluation et les signatures. Les anciennes versions restent conservées dans l’historique administratif.

La désactivation bloque l’accès des autres rôles et les téléchargements. La suppression est logique et réversible par l’administrateur, pour conserver la traçabilité. Les PDF archivés ou désactivés sont signalés comme tels.

Les versions conservent les données, l’identité, la signature, l’auteur et l’horodatage. Les PDF sont régénérés depuis ces instantanés ; il ne s’agit pas d’un service de signature électronique qualifiée, ni d’une archive de fichiers PDF immuables.

## Contrôles

Les accès sont limités à l’organisme et aux personnes concernées. Les écritures sont protégées par jetons CSRF et verrouillage optimiste ; les choix du questionnaire sont validés côté serveur. Un formulaire tronqué est refusé. Les documents utilisent des réponses privées sans mise en cache.

Les tests couvrent le parcours HTTP complet, les deux téléchargements, les affectations automatiques, les modifications et l’historique, l’isolement des organismes, l’accès du stagiaire avant publication et après désactivation, les conflits de version et la signature réservée aux signataires concernés.

## Convention pour les prochains formulaires

Utiliser Tom Select pour les listes déroulantes et Flatpickr en français pour les dates, avec affichage jj/mm/aaaa et envoi serveur Y-m-d. Le composant commun public/dist/js/enhanced-forms.js initialise les contrôles dans un conteneur data-enhanced-form. Charger Tom Select, Flatpickr et leurs styles avant ce composant. Après ajout dynamique de champs, appeler WikiFormationForms.init(conteneur). Détruire les instances Tom Select avant de reconstruire des champs ; utiliser leur méthode setValue pour les valeurs proposées automatiquement. Conserver les cases à cocher prévues par les modèles et les droits des champs en lecture seule.

## Affectation automatique et transmission (octobre 2026)

Les inscriptions non annulées et non absentes reçoivent leur dossier à partir du premier jour de session (date civile Europe/Paris). Les sessions annulées sont exclues. Le modèle actif, l’entreprise et le formateur doivent être renseignés dans le même organisme. Tous les formateurs effectifs de la session sont affectés au questionnaire partagé. Les dossiers existants, même désactivés, ne sont jamais écrasés.

La synchronisation est réalisée à l’ouverture du tableau de bord formateur et de la liste des habilitations (administrateur/formateur), ainsi qu’au déploiement et lors du traitement existant `app:trainer-reminders:run --execute` (le conteneur local le lance chaque minute). Elle rattrape également les sessions passées. Pour une création sans visite, planifier quotidiennement sur le serveur (ou toutes les heures) :
```sh
cd "$HOME/apps/wkformation" && php bin/console app:habilitations:sync --env=prod --no-debug
```
L’option `--entite=1` permet de limiter l’organisme. La commande indique les inscriptions dont l’entreprise ou le formateur manque.

Seul l’administrateur affecte ou réaffecte, via « Modifier l’affectation » dans la liste. Plusieurs formateurs peuvent remplir le même questionnaire ; la signature identifie celui qui valide. Réaffecter remet l’évaluation à zéro et conserve les versions précédentes dans l’historique. Le formateur accède à ses évaluations via Habilitations ou le menu de chaque session. Après signature, l’état devient « Validé · à transmettre ». L’administrateur contrôle puis utilise « Transmettre à l’entreprise ». Avant cette action, aucun accès entreprise au dossier ni au PDF n’est autorisé. Une modification de l’avis révoque cet accès et exige une nouvelle signature et transmission. Les dossiers déjà transmis avant cette évolution restent accessibles.
