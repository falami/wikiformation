# CV professionnels des formateurs

Exécuter la migration Version20261015023000 avant déploiement. Le stockage PDF est privé, dans var/storage/trainer-cv (à inclure dans les sauvegardes).

La liste et la fiche administrateur proposent « CV ». Les diplômes existants sont accessibles depuis cette fiche. Le formateur dispose d’un lien dans Mon profil professionnel. Les stagiaires trouvent « Découvrir mes formateurs » dans les actions de leurs sessions ; seule la version publiée est accessible, sans PDF source ni diplômes joints.

## Préremplissage gratuit

Le PDF est traité sur le serveur par smalot/pdfparser, déjà présent dans Composer. Aucun appel externe, modèle IA, clé API ou facturation par document. Les anciennes variables TRAINER_CV_OPENAI_KEY et TRAINER_CV_OPENAI_MODEL ne sont plus utilisées.

Limites : 10 Mo, 20 pages ; texte sélectionnable nécessaire (pas d’OCR). Les rubriques sont détectées par leurs titres français ou anglais. Aucune expérience ni qualification n’est inventée ; le titre professionnel reste à saisir. Les PDF complexes peuvent produire une proposition partielle ou nécessiter une saisie manuelle. Le filtrage des coordonnées est une aide : la relecture avant publication reste nécessaire.

La proposition est conservée séparément et ne remplace ni le brouillon ni la publication sans action du formateur. Un échec conserve le PDF déposé et le CV existant. Le préremplissage peut être relancé pour un PDF déjà déposé. Déployer le code avec les dépendances Composer habituelles et vider le cache Symfony ; aucun service supplémentaire à installer chez o2switch.
