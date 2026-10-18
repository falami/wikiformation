# Recrutement des formateurs

## Parcours
- Page publique : /fr/recrutement (lien « Devenir formateur »).
- Gestion globale : menu SUPER ADMIN > Recrutement, /fr/super/{entite}/recrutement.
- Seul ROLE_SUPER_ADMIN consulte les candidatures, leurs pièces et effectue les affectations.
- La sélection multiple d’entités crée un seul Utilisateur et une fiche Formateur distincte par organisme, avec rôle TENANT_FORMATEUR. Chaque nouvelle fiche reçoit les tarifs, coordonnées professionnelles, spécialités, mobilité, CV et portrait.
- Un compte portant déjà cet email nécessite une confirmation d’identité. Les comptes et profils existants ne sont pas écrasés. Répéter une affectation n’en crée pas de doublon.
- Aucun email automatique n’est envoyé à la conversion ; le titulaire utilise « Mot de passe oublié ».
- Le CV est un PDF (10 Mo maximum), le portrait un JPG/PNG (5 Mo maximum, réencodé). Les références sont enregistrées en base ; les originaux candidats sont privés dans var/storage/recruitment. Les copies des CV retenus sont dans var/storage/trainer-cv. Inclure ces répertoires dans les sauvegardes.
- Les notes et les statuts permettent la présélection, l’acceptation et l’archivage.

## Déploiement
Appliquer la migration Doctrine Version20261018194500 avant de servir le nouveau code, puis vider le cache Symfony. Cette migration conserve les fiches existantes et remplace l’unicité globale du formateur par une unicité utilisateur/entité.
Ne pas lancer doctrine:schema:update --force : le projet comporte d’autres écarts préexistants (notamment la table notification_receipt, gérée séparément).

## Référentiels
- 2 775 descripteurs Formacode® V14, Centre Inffo, Licence Ouverte 2.0 :
  https://www.data.gouv.fr/datasets/formacode-r-version-14-thesaurus-de-loffre-de-formation-et-de-certification
  Source RDF du 12 décembre 2024, téléchargée le 18 octobre 2026. Extraction des codes et libellés préférentiels français dans public/dist/recruitment/subjects.json.
  Il s’agit d’un thésaurus de domaines de formation, pas d’un inventaire exhaustif de toutes les offres françaises. Le candidat peut ajouter des spécialités libres.
- Contours des 101 départements : Etalab, Licence Ouverte 2.0 :
  https://etalab-datasets.geo.data.gouv.fr/contours-administratifs/latest/geojson/departements-1000m.geojson
  Téléchargement du 18 octobre 2026, projection simplifiée en SVG et encarts pour les cinq DROM. Liste accessible synchronisée avec la carte.
- Tom Select 2.4.3 distribué localement ; licence dans public/dist/recruitment/TOM-SELECT-LICENSE.txt.

## Validation
Tests d’intégration : RecruitmentTest, TrainerProfileTest, TrainerWorkflowTest, HabilitationTest.
La soumission publique est protégée par CSRF, champ piège et limitation à cinq tentatives par heure et par adresse IP.
