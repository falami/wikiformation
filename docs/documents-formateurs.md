# Documents formateurs et suivi des émargements

## Utilisation

- Administration : **Formateur → Documents**. Ajouter un intitulé, une catégorie,
  éventuellement des consignes, puis un fichier PDF, DOCX, XLSX, ODT ou ODS
  (20 Mo maximum, sous réserve des limites PHP du serveur).
- Cocher **Disponible dans l’espace formateur** pour publier. Décocher retire le
  téléchargement aux formateurs sans supprimer le fichier ni son historique.
- **Gérer** permet de remplacer le fichier et de renseigner une note de mise à jour.
  Chaque remplacement conserve la version précédente, accessible à l’administrateur.
  Les formateurs téléchargent toujours la dernière version publiée.
- Espace formateur : **Documents** ou **Documents vierges** sur le dashboard.
  Trois PDF vierges sont aussi disponibles : émargement, appréciation formateur,
  appréciation stagiaire. Ils utilisent le nom et le logo de l’entité courante.
  Un logo absent ou invalide laisse le nom de l’organisme affiché.
- Les fichiers déposés restent inchangés : inclure le logo souhaité dans le document.
  Télécharger les modèles à l’avance pour pouvoir les utiliser sans connexion.

Une session marquée **Sous-traitance = Organisme de formation** utilise le suivi
des émargements du donneur d’ordre. Elle est exclue des alertes d’émargement du
dashboard et des critères d’émargement du dossier en liste. Les anciens émargements
ne sont pas supprimés et restent accessibles.

## Déploiement

La migration `DoctrineMigrations\Version20261001150000` crée les deux tables de la
bibliothèque. Exécuter cette migration avec le déploiement du code. Elle a été
appliquée sur l’environnement Docker local pendant la mise en place.

Les fichiers sont stockés dans `var/storage/documents-formateurs`, hors du dossier
public. Ce répertoire doit être persistant, sauvegardé avec la base et accessible
en écriture au processus PHP. Les téléchargements passent par les contrôles de
rôle et d’entité ; les anciennes versions sont réservées aux administrateurs.

## Vérifications

Les suites `AttendanceScopeTest`, `DocumentFormateurLibraryTest` et
`BlankTrainingDocumentPdfTest` utilisent des bases et fichiers temporaires. Elles
couvrent les exclusions d’émargement, la publication, les versions et leurs
conflits, les droits d’accès, la validation des fichiers et les PDF avec logo.
