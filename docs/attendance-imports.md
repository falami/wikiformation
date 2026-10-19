# Import d’émargements papier

Accès : tableau de bord administrateur, « Émargements à régulariser » → « Importer une feuille ».

1. Choisir la session et déposer un PDF ou DOCX (15 Mo, 20 pages maximum).
2. Examiner les pages. L’OCR local repère les noms/dates quand ils sont lisibles ; ces rapprochements ne constituent pas une validation de signature.
3. Choisir chaque stagiaire/date/demi-journée puis encadrer la signature correspondante. Ajouter plusieurs extraits et retirer les erreurs avant validation.
4. Confirmer les correspondances. Les seuls créneaux choisis sont régularisés. L’original est consultable dans l’historique des imports.

L’import est assisté : la reconnaissance des écritures manuscrites et la localisation automatique fiable des signatures ne sont pas garanties. Le cadrage de chaque signature est réalisé par l’administrateur. Les signatures électroniques existantes et les présences papier déjà saisies ne sont jamais remplacées. Les dates futures et inscriptions absentes/annulées sont exclues. Cette version traite les stagiaires disposant d’une inscription et d’un compte ; les émargements formateurs et invités sans compte restent hors de ce parcours.

## Serveur

PHP GD et Zip, `pdfinfo` et `pdftoppm` (Poppler) requis. `tesseract` avec les langues `fra` et `eng` active la reconnaissance locale. Sans OCR, l’aperçu et le rapprochement visuel restent disponibles. `libreoffice` est nécessaire uniquement pour Word ; sinon utiliser un PDF. Les documents Word avec dépendances externes, macros ou objets intégrés sont refusés : exporter en PDF.

Le Dockerfile inclut les paquets Debian nécessaires : **reconstruire et redéployer l’image** pour les installer. Sur un autre hébergement, installer ces exécutables et les rendre accessibles dans le PATH du processus PHP. Les conversions doivent fonctionner sous un utilisateur sans privilèges, avec limites de ressources et sans accès réseau sortant. Prévoir un timeout HTTP suffisant pour les documents scannés ; réduire la taille du document si nécessaire.

## Stockage et preuve

Originaux, pages et texte OCR : `var/storage/attendance-imports/<entite>/<token>/`, hors du répertoire public. Sauvegarder ce dossier avec la base. Les routes de consultation vérifient les droits SESSION_MANAGE et l’entité. Aucun fichier n’est transmis à un fournisseur OCR externe.

Chaque validation crée un AuditLog ATTENDANCE_PAPER_IMPORTED avec empreinte SHA-256 du fichier, page, coordonnées et inscription/créneau. L’inscription conserve une présence manuelle « paper » avec administrateur et date d’import. L’image découpée est conservée dans Emargement.signatureDataUrl pour les relevés existants ; signedAt, IP et userAgent ne sont pas fabriqués. L’horodatage de validation ne prétend pas être la date de signature papier.

Aucune migration nécessaire. Les imports non validés ne changent aucune présence ; ils restent stockés pour éviter de perdre une préparation et nécessitent une politique de conservation adaptée par l’exploitant.

### Production cPanel : outil introuvable

Les installations du conteneur Docker local ne sont pas transférées par Git sur cPanel.
Depuis le dossier de l’application, lancer :

```sh
php bin/console app:attendance-import:check --env=prod
command -v pdftoppm
command -v pdfinfo
```

Si les outils sont installés dans un dossier personnalisé, définir dans `.env.local` du **serveur** (chemin réel fourni par l’hébergeur) :

```dotenv
ATTENDANCE_TOOLS_DIR=/chemin/vers/le/dossier/bin
```

L’application recherche aussi les dossiers système usuels indépendamment du PATH PHP. Si `.env.local.php` a été généré par `composer dump-env prod`, régénérer ce fichier pour prendre en compte le réglage, puis vider le cache de production selon la procédure habituelle.

Si les exécutables sont absents, demander à l’hébergeur : « Pouvez-vous installer ou rendre accessibles à mon PHP Poppler (`pdftoppm`, `pdfinfo`), autoriser `proc_open`, et me fournir le chemin des exécutables ? Tesseract avec les langues français/anglais est nécessaire pour l’OCR, et LibreOffice Writer pour le DOCX. » Ne pas utiliser sudo sur un hébergement mutualisé. Aucun changement de base de données n’est requis pour ce réglage.
