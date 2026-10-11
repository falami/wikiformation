# Contrôle technique des documents de formation — 8 octobre 2026

Périmètre : actions de formation. Cette revue ne vaut ni certification Qualiopi ni visa de l’administration. Les informations déclarées, les preuves de réalisation et les procédures de l’organisme doivent être contrôlées par ses responsables. Les pièces déjà signées ne doivent pas être réécrites silencieusement après une évolution de modèle.

## Corrections apportées

- Le certificat de réalisation reprend les heures effectivement justifiées et la période de formation. Le taux de présence par demi-journée ne sert plus de multiplicateur de la durée. Les pauses sont exclues ; une présence partielle avec pause globale non répartie exige des créneaux précis. Les chevauchements, horaires futurs, présences inconnues et durées nulles empêchent l’émission d’un certificat final.
- Une inscription non clôturée produit uniquement un projet clairement identifié. L’automatisation bloque aussi l’envoi sans responsable et signature configurés. Les PDF ne présentent plus la participation comme une réussite pédagogique, une qualification ou un document valable sans signature.
- Les signatures de participants sans compte, rattachées à l’inscription par leur accès individuel, sont prises en compte. Le choix d’une inscription dans le formulaire d’attestation est limité à l’organisme courant.
- Les contrats individuels rappellent le délai de dix jours, l’absence de paiement anticipé, le premier versement plafonné à 30 %, l’échelonnement et la force majeure. Une annexe conditionnelle détaille les droits des consommateurs contractant à distance ou hors établissement et fournit le modèle de formulaire de rétractation. Aucun consentement au commencement anticipé n’est présumé.
- Les conventions identifient les prérequis, intervenants et références professionnelles, modalités de suivi et sanction. Les conditions financières manquantes sont signalées explicitement. Le numéro de déclaration d’activité est accompagné de la mention du préfet de région et ne constitue pas un agrément de l’État.
- La part des enseignements à distance est renseignable dans chaque session, dans les espaces administrateur et commercial. `null` signifie inconnu et ne devient jamais automatiquement 0 %. Le pourcentage figure dans la convention ; la valeur absente est signalée avant signature. Migration nécessaire : `Version20261008194000`.
- Suppression de la certification Qualiopi affirmée sans justificatif sur la feuille d’émargement et de la confusion entre référence interne d’une formation et enregistrement administratif dans le contrat formateur. Les informations publiques supplémentaires de la formation sont reprises au programme PDF lorsqu’elles sont renseignées.

## Vérifications

Tests unitaires et intégration sur bases SQLite jetables : durées inégales des demi-journées, pauses, preuves manquantes, dates, chevauchements, clôture, absence de signature automatique, limites du taux à distance, clauses individuelles, isolation des inscriptions et parcours conventions/sessions. Contrôle de syntaxe Twig, conteneur et métadonnées Doctrine. Rendu de PDF fictifs et inspection de toutes leurs pages : certificat sur une page, convention et annexe lisibles sans débordement. Aucun e-mail réel ni modification des dossiers de production par ces vérifications.

## Points qui nécessitent une validation de l’organisme

- Compléter les programmes, qualifications des intervenants, moyens à distance et d’accompagnement, prix contractuels TTC et échéanciers, règles d’abandon, délais d’accès et modalités de sanction. La présence du champ ne démontre pas la véracité de son contenu.
- Vérifier que la région indiquée pour le numéro de déclaration correspond bien à l’enregistrement administratif, notamment après un déménagement.
- Vérifier les coordonnées de l’organisme recevant une rétractation. Le formulaire fourni ne constitue pas un portail de traitement automatique : les demandes, remboursements et éventuels consentements séparés doivent être tracés par l’organisme. Les CGV, la médiation de la consommation et les cas particuliers de financement restent à examiner selon l’activité réelle.
- Contrôler les anciens documents émis avec les anciens modèles et corriger les erreurs selon une procédure de versionnement, en conservant l’original et la trace de correction.
- Une réalisation déduite des présences ne remplace pas les traces d’activité spécifiques à la formation à distance. Les documents contractuels signés doivent correspondre à la version effectivement présentée au signataire.

## Références officielles consultées

- [Code du travail — L.6353-3](https://code.travail.gouv.fr/code-du-travail/l6353-3), [L.6353-4](https://code.travail.gouv.fr/code-du-travail/l6353-4), [L.6353-5](https://code.travail.gouv.fr/code-du-travail/l6353-5), [L.6353-6](https://code.travail.gouv.fr/code-du-travail/l6353-6), [L.6353-7](https://code.travail.gouv.fr/code-du-travail/l6353-7) : contrat individuel et garanties financières.
- [D.6353-1](https://www.legifrance.gouv.fr/codes/id/LEGISCTA000018522262) : contenu des conventions et proportion à distance ; apprécier le champ du financement et les dispositions d’entrée en vigueur.
- [R.6351-6](https://code.travail.gouv.fr/code-du-travail/r6351-6) et [L.6352-12](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000006904409) : mention de l’enregistrement et absence d’agrément de l’État.
- [Ministère du Travail — modèle de certificat de réalisation](https://travail-emploi.gouv.fr/certificat-de-realisation-de-formation-professionnelle-modele-de-document).
- [Code de la consommation — L.221-18](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226842), [L.221-24](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226828), [L.221-25](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226826), [R.221-1](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000045418603), [annexe du formulaire type](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000045418600).
- [Ministère de l’Économie — droit de rétractation à distance](https://www.economie.gouv.fr/particuliers/mes-droits-conso/bien-consommer/vente-distance-tout-savoir-sur-votre-droit-de-retractation) ; [DGCCRF, bulletin du 10 septembre 2024 reprenant le formulaire réglementaire](https://www.economie.gouv.fr/files/files/directions_services/dgccrf/boccrf/2024/BOCCRF-n9-10092024.pdf).
