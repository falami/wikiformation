# Chartes qualité des formateurs

Recherche et mise en œuvre du 15 octobre 2026.

## Cadre juridique et choix du produit

Les sources officielles consultées ne posent pas une obligation générale, applicable à tous les organismes, de faire signer un document autonome portant le nom « charte qualité ». Une charte formalise les engagements ; elle ne remplace ni le contrat de prestation ni les preuves de mise en œuvre du système qualité.

Pour les prestations sous-traitées sur Mon Compte Formation, un contrat écrit est obligatoire. Il précise notamment les missions, le contenu et la sanction de la formation, les moyens, les conditions de réalisation et de suivi, la durée, la période et le montant. L’organisme donneur d’ordre reste responsable. Le périmètre CPF comporte aussi des exigences propres aux sous-traitants et des exceptions : la signature d’une charte ne les vérifie pas automatiquement.

Sources : [Caisse des Dépôts, sous-traitance et CPF](https://of.moncompteformation.gouv.fr/espace-public/sous-traitance-et-cpf-ce-qui-change-depuis-le-1er-avril-2024), [FAQ du ministère du Travail](https://travail-emploi.gouv.fr/compte-personnel-de-formation-cpf-encadrement-de-la-sous-traitance-des-organismes-de-formation-foire-aux-questions).

Le guide Qualiopi, indicateur 27, porte sur la maîtrise de la qualité des prestations sous-traitées. Une charte est un élément possible du dossier ; le suivi effectif des intervenants, de leurs compétences et de leurs prestations reste nécessaire. [Guide officiel Qualiopi](https://travail-emploi.gouv.fr/sites/travail-emploi/files/2024-07/guide_qualiopi_0.pdf).

Le modèle proposé est un point de départ original, inspiré des thèmes des trois documents fournis : préparation, qualifications, accessibilité, présences, évaluations, confidentialité et amélioration. Il ne reprend pas les signatures, marques ou clauses commerciales particulières des organismes cités. Adapter les engagements aux pratiques réelles avant publication.

## Parcours

- Administrateur : **Formateur → Chartes qualité → Créer une charte**. Rédiger les chapitres et sous-chapitres, ordonner les chapitres, choisir la date avec le calendrier, enregistrer le brouillon puis publier.
- Publier fige le contenu. « Nouvelle version » copie les engagements dans un brouillon avec un nouveau numéro. La dernière version publiée devient celle demandable, même si sa date d’application est future.
- Dans le suivi : sélectionner les sous-traitants avec TomSelect, éventuellement tous, puis demander leur signature. Les demandes apparaissent dans leurs tableaux de bord ; aucun courriel n’est envoyé par cette fonctionnalité.
- Le formateur lit la version, saisit son nom et accepte expressément depuis son compte. Il retrouve les demandes et les anciens exemplaires dans **Mes chartes qualité**.
- L’administrateur voit les signatures, les demandes en attente, les anciennes versions acceptées et les personnes non sollicitées. Il télécharge un PDF individuel ou le ZIP comprenant le texte de la version, les PDF signés et un manifeste de preuve JSON.
- Le parcours **Contrats** existant reste distinct, avec sa notification de signature sur le tableau de bord. Une charte signée ne signe pas automatiquement un contrat de mission.

## Signature et conservation

Acceptation électronique simple depuis un compte authentifié, avec nom saisi, consentement explicite, date serveur, identité du compte, adresse IP, agent utilisateur et empreinte SHA-256 du contenu. Le PDF est généré à la signature et ses octets sont conservés en base, avec une empreinte vérifiée au téléchargement. Une nouvelle version n’altère pas cet exemplaire.

Il ne s’agit pas d’une signature électronique qualifiée, d’un horodatage qualifié ni d’un archivage probatoire certifié. L’empreinte seule ne garantit pas l’identité civile du signataire ; la sécurité du compte et la conservation du dossier restent essentielles. [Code civil, article 1367](https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000032042456/2021-10-30).

Les accès sont limités à l’organisme et au formateur destinataire. Les publications et signatures ne disposent pas de suppression dans l’interface. Inclure ces tables et les PDF en base dans les sauvegardes ; appliquer la politique de conservation et d’information des personnes de l’organisme. Les contraintes de clé étrangère empêchent la suppression accidentelle des formateurs possédant des preuves.

## Déploiement

Aucune clé API ni service de signature payant nécessaire pour ce parcours. Dépendances existantes : Dompdf, extension PHP ZIP ; TomSelect et Flatpickr chargés comme ressources frontales.

Migration additive : `DoctrineMigrations\Version20261015123000`. Appliquée en environnement Docker local, pas en production. Déployer le code, les templates et les fichiers `public/dist`, exécuter la migration puis vider le cache de production. Aucun envoi réel ni aucune signature de formateur n’a été effectué pendant le développement.

Validation : tests d’intégration de publication, immutabilité, demandes idempotentes, consentement, CSRF, périmètre organisme/formateur, remplacement de version, conservation du PDF et export ZIP ; vérification du parcours de contrats existant et du rendu PDF.
