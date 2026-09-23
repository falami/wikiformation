# Formateurs, planning et contrats

## Retrouver les contrats de l’organisme

Dans **Formateur → Contrats**, la liste est limitée à l’entité courante. Les filtres permettent de rechercher par statut, formateur et session. Les actions visibles ouvrent la fiche administrateur, le PDF ou l’édition d’un brouillon. L’administrateur n’est plus envoyé vers la page de signature réservée au formateur.

La session contient également un lien vers cette bibliothèque. Un contrat reste dans la bibliothèque même si son formateur est ensuite retiré du planning.

## Affecter plusieurs intervenants

Dans **Session → Modifier → Planning & intervenants** :

1. Choisir le formateur par défaut, utilisé lorsqu’un créneau n’a pas d’intervenant spécifique.
2. Ajouter une **Journée**, une **Matinée** ou un **Après-midi**.
3. Ajuster les dates et horaires avec les sélecteurs de dates.
4. Choisir l’intervenant de chaque créneau avec TomSelect, puis enregistrer.

Exemple : le 5 octobre, Alice de 9 h à 12 h 30 et Bruno de 13 h 30 à 17 h. La session affiche les deux intervenants ; chaque formateur voit sa propre intervention dans son calendrier. Les créneaux qui se chevauchent dans une session, ou qui occupent déjà ce formateur dans une autre session non annulée du même organisme, sont refusés lors de l’enregistrement. Deux créneaux adjacents sont acceptés.

Ce fonctionnement permet une succession de formateurs. La coanimation simultanée d’un même créneau par plusieurs formateurs n’est pas modélisée par cette évolution.

Les feuilles d’émargement regroupent les créneaux d’une même date. Les intervenants par demi-journée sont pris en compte dans les signatures attendues, et un formateur ne peut pas signer une période sur laquelle il n’intervient pas. La séparation matin/après-midi utilisée par l’application est 13 h.

## Préparer le contrat de chaque formateur

Depuis la fiche session, **Préparer le contrat** crée un brouillon pour le formateur concerné. Une seconde demande ouvre le contrat existant. Le formulaire est protégé par un jeton CSRF.

Le PDF reprend uniquement ses créneaux, leur durée, et l’intitulé de la session (y compris un intitulé libre en sous-traitance OF). Le montant proposé utilise le tarif du formateur. En mode journalier, l’estimation compte une demi-journée jusqu’à quatre heures par date civile, une journée au-delà. Le montant du brouillon reste modifiable pour correspondre à l’accord commercial ; vérifier ce montant avant signature.

Un document signé, archivé ou résilié est lu depuis le PDF conservé, sans régénération à partir du planning actuel. Si le fichier original manque, la fiche l’indique : il faut restaurer l’original. L’évolution du planning ne réécrit pas les contrats déjà signés.

## Installation et vérification

Les durées nettes et les versions de contrats nécessitent les trois migrations décrites dans [Durées, effectifs et versions](durees-effectifs-versions.md). Les installations plus anciennes doivent aussi avoir appliqué les migrations listées dans `devis-conventions.md`.

## Modifier un contrat et conserver ses versions

Depuis la fiche d’un contrat, **Modifier** ouvre son brouillon avec les sélecteurs TomSelect existants. Renseigner le motif du changement puis enregistrer. La version précédente reste consultable dans **Historique des versions**, avec son auteur, sa date, son motif, ses montants et son PDF conservé.

Pour un document envoyé ou signé, choisir **Créer une nouvelle version**. Cette action archive le document précédent et ouvre un nouveau brouillon. Les signatures antérieures restent dans l’archive ; la nouvelle version doit être signée à nouveau. Une page de signature ouverte avant la révision est refusée. Un contrat comportant un historique ne peut plus être supprimé depuis l’interface.

Les horaires restent gérés dans le planning de la session avec les sélecteurs de dates. Les nouvelles versions utilisent ces horaires et les heures de formation hors pauses. Un PDF signé ne peut pas être reconstitué s’il est manquant : il faut restaurer son original avant de préparer la nouvelle version.

Les PDF des anciennes versions sont stockés hors du dossier public, dans `var/storage/contrat-formateur-versions`. Sauvegarder ce dossier avec la base de données et les fichiers habituels de l’application. Ne pas le supprimer lors d’un déploiement ou d’un nettoyage du cache. Les archives antérieures à cette fonctionnalité ne peuvent pas être reconstituées automatiquement.

```sh
php -d memory_limit=512M bin/phpunit
php bin/console lint:twig templates --env=test
php bin/console lint:container --env=test
php -d memory_limit=512M bin/console cache:clear
```

Les nouveaux tests HTTP utilisent SQLite en mémoire, des comptes fictifs et un service d’e-mail simulé. Ils couvrent les calendriers, l’enregistrement de deux intervenants, les chevauchements, les contrats, les signatures et le rattachement de conventions. Ils ne modifient pas les dossiers réels. Ces évolutions améliorent la gestion documentaire ; elles ne constituent pas un audit de conformité Qualiopi complet.
