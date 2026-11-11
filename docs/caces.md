# Module CACES

Module indépendant des évaluations travail en hauteur. Entrée : `/fr/caces/{entite}` et rubrique « Certification CACES® » des espaces administrateur, formateur et stagiaire. Les actions des sessions proposent également « Préparer les tests CACES® ».

## Fonctionnement

- 40 grilles théoriques V1 12/2024 des huit recommandations fournies : R482, R483, R484, R485, R486, R487, R489, R490. R488 n'est pas ajouté comme recommandation CACES.
- Les pages originales sont conservées en images privées, avec leurs illustrations et abaques partagés. Les candidats répondent A/Vrai ou B/Faux dans un panneau séparé. Les énoncés importés restent dans les pages originales ; il ne s'agit pas d'une retranscription éditable de chaque illustration.
- Les corrigés, empreintes SHA-256 et modèles sont conservés dans `resources/caces/catalog.json`, hors du répertoire public. Seules des routes authentifiées délivrent les pages, pendant la tentative pour le candidat. Aucun corrigé n'est envoyé au navigateur du candidat.
- Création de modèles propres à l'organisme, brouillons, publication, duplication en nouvelle version et archivage. Les super-administrateurs peuvent publier une version commune remplaçant son prédécesseur pour les prochains tirages. Les sessions conservent une copie de leur modèle.
- Tirage aléatoire d'une grille par session/recommandation ; même grille pour ses candidats et ses catégories. Tous les inscrits hors absents/annulés sont affectés. Les deux testeurs peuvent différer du formateur de la session.
- La théorie dure 60 minutes à compter du démarrage serveur. Sauvegarde à chaque modification et périodiquement. Les réponses reçues à l'échéance ou après ne remplacent jamais les réponses enregistrées. Le serveur finalise également un test expiré lors de sa consultation.
- Seuil global de 70/100 et seuil par thème arrondi au supérieur. La répartition des thèmes suit chaque grille, sans appliquer les quatre thèmes R489 aux autres recommandations.
- Pratique : critères de 1 à 8 points, seuils par thème et global, zéro éliminatoire, durée réelle saisie en secondes ; le dépassement élimine sans bloquer la saisie.
- Fiche candidat et trois signatures manuscrites horodatées. Les testeurs affectés signent eux-mêmes leur relevé ; un administrateur ne peut pas signer à leur place. Les signatures sont des tracés manuscrits authentifiés, sans service de signature qualifiée.
- Documents imprimables (impression navigateur / PDF) : synthèse, attestation provisoire, carte par catégorie, attestation d'échec. Export CSV du registre, avec protection contre l'injection de formules.
- Reprise de théorie pour une autre catégorie de la session, extension depuis un dossier certifié ou depuis un certificat externe vérifié par l'administrateur. Une dispense externe conserve les références et l'auteur du contrôle, sans inventer une note.
- Obtention à la plus tardive des deux réussites. Validité ancrée au premier CACES complet de la même recommandation/session ou à l'origine de l'extension. Durée : 5 ans moins un jour, 10 ans moins un jour en R482. Le délai d'extension est contrôlé depuis l'origine, sans prolongation par chaînage.
- Émission après finalisation des catégories de la session, pour figer leur date commune de validité. Le numéro suit `année.recommandation.Ccatégorie.identifiant` ; le dernier segment est l'identifiant unique du dossier, pas un compteur réinitialisé par catégorie chaque année.

## Périmètre actuel

La grille pratique R489 catégorie 3 est préconfigurée à partir des pages 36–37 de R489A juillet 2026 (10/20/55/15). Les autres catégories disposent de l'éditeur mais leurs grilles pratiques ne sont pas préremplies. La bibliothèque ne réalise pas de veille automatique : les nouvelles versions doivent être préparées et publiées par l'équipe Wikiformation. Les habilitations des testeurs et la certification OTC de l'organisme restent à vérifier par l'organisme ; l'affectation contrôle les comptes formateurs actifs.

Le justificatif d'un CACES externe doit être conservé dans le dossier documentaire du stagiaire ; le module conserve sa référence et la déclaration de vérification, sans téléversement de ce justificatif dans un nouveau stockage. Les documents portent les informations demandées ; leur mise en production doit être rapprochée de la procédure documentaire de l'OTC. Le questionnaire travail en hauteur et ses routes, modèles et règles n'ont pas été modifiés.

## Installation et exploitation

La migration `DoctrineMigrations\Version20261111120000` crée uniquement `caces_template`, `caces_exam`, `caces_attempt`. Elle a été appliquée à Docker local le 11 novembre 2026. Aucun déploiement de production n'a été effectué.

```sh
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261111120000' --up --no-interaction
php bin/console app:caces:expire
```

Exécuter `app:caces:expire` chaque minute avec le planificateur du déploiement pour finaliser également les tentatives abandonnées sans nouvelle consultation. La limite serveur des réponses ne dépend pas de cette planification. Aucune nouvelle tâche planifiée de production n'a été installée.

Les ressources privées (environ 78 Mo) doivent être déployées avec le code. Le document root doit rester `public/`. La migration inverse est volontairement interdite pour protéger les dossiers d'examen.

## Import reproductible

```sh
python scripts/caces/import_sources.py '/chemin/vers/GRILLES'
python scripts/caces/practice_r489.py
```

Le premier script requiert PyMuPDF et vérifie le nombre et l'ordre des 100 réponses dans chaque grille. Le second ajoute la grille pratique. L'import utilise l'ordre réel des affirmations lorsque le PDF porte un repère erroné ; les repères divergents sont conservés et affichés au candidat. Deux repères de section/illustration parasites sont explicitement exclus : R486 grille 4 page 2 et R490 grille 4 page 4. Les énoncés sont extraits des cellules du tableau, délimitées par les lignes des colonnes de réponse et les bandeaux de rubrique. Quatre cellules sans numéro imprimé sont repérées explicitement dans `source_statements.py` après vérification visuelle. Les 4 000 libellés reprennent ainsi les énoncés sources plutôt que des intitulés génériques. Les pages originales restent inchangées pour conserver les schémas partagés. L’import conserve les grilles pratiques existantes. Les modèles personnalisés et les instantanés des examens déjà créés ne sont pas réécrits.

## Vérifications

Tests PHPUnit dédiés : seuils impairs, total 70/100, zéro pratique, temps pratique, refus des réponses tardives et multiples, dates, intégrité des 40 grilles, accès par rôle, CSRF, parcours avec signatures et documents, testeur extérieur à l'équipe de session, dispense externe. Tests de non-régression du module Evaluation séparés. Lint Twig, conteneur Symfony et JavaScript.

Contrôle visuel du tableau de bord et de la bibliothèque avec des données fictives via le navigateur. La connexion réelle n'a pas été contournée. Pour reproduire le rendu de test uniquement : `CACES_QA_OUTPUT=/tmp/caces-preview php vendor/bin/phpunit tests/Integration/CacesPortalsTest.php` après création du répertoire temporaire.

### Parcours pratique
La création dédiée (`modeles/nouveau?kind=practice`) accepte des critères notés de 1 à 8 points. La session filtre les grilles publiées par recommandation et catégorie et propose la création contextuelle en cas d’absence. Le testeur affecté renseigne chaque note dans son espace CACES. Un administrateur peut changer le testeur pour toute la session depuis un dossier ; les évaluations signées conservent leur signataire dans les documents et le registre. Les changements sont tracés dans le snapshot pratique.


### Bibliothèque pratique 2026

`resources/caces/practice-2026.json` contient 37 grilles couvrant les 33 catégories des huit recommandations fournies. Les variantes R482 A, B2 et C1 sont distinctes ; les catégories R482 A/G et R489 7 comportent les deux évaluations d’engins requises. Chaque engin est noté sur 100 et doit réussir séparément ; le score global affiché est alors leur moyenne. Les critères communs des PEMP R486 sont comptés une seule fois.

Les barèmes ne sont plus plafonnés à 8 : la saisie accepte notamment 10, 15, 20 et 30 points conformément aux tableaux. Les modèles personnalisés autorisent de 1 à 100 points par critère, avec exactement 100 points par épreuve. Les options complémentaires sur 50 points ne font pas partie de cette bibliothèque par catégorie. La durée de 60 minutes reste une valeur proposée à adapter dans une copie du modèle à la procédure de l’organisme ; elle n’est pas extraite des unités de test.

Import reproductible avec PyMuPDF : `python scripts/caces/import_practice.py /chemin/vers/les/referentiels`. L’import utilise la géométrie des tableaux et vérifie les totaux ; il conserve les consignes de chaque point d’évaluation, les pages sources et leur empreinte SHA-256. Les pages PNG privées sont consultables par les administrateurs depuis le modèle. L’ancien modèle R489 catégorie 3 reste accessible sous sa clé d’origine, archivé pour les nouvelles affectations ; les snapshots des sessions existantes ne sont pas modifiés.
