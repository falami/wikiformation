# Préparation BPF

Référentiel vérifié le 8 octobre 2026 : Cerfa 10443*17 et notice 50199#17.

- Formulaire : https://www.formulaires.service-public.fr/gf/cerfa_10443.do
- Notice : https://www.formulaires.service-public.fr/gf/getNotice.do?cerfaNotice=50199&cerfaFormulaire=10443
- Téléservice : https://www.monactiviteformation.emploi.gouv.fr/mon-activite-formation/

## Périmètre

Le module prépare les cadres A à H et exporte un document interne. Il ne dépose pas le BPF, ne signe pas le Cerfa et ne garantit pas l'exactitude des données comptables. Les consignes de campagne et les éventuelles évolutions du formulaire doivent être revues avant chaque dépôt. Le formulaire officiel reste accessible depuis la page.

La période est celle de l'exercice comptable, pas nécessairement une année civile. Les dossiers sont isolés par organisme et dates exactes. La génération propose des zéros pour les rubriques sans activité trouvée uniquement lorsque le cadre ne contient pas de lacune identifiée. Dans un cadre partiel (pièce non classée, présence manquante), les rubriques sans résultat restent vides et les valeurs connues restent des sous-totaux à compléter ; le total D est laissé vide si des dépenses restent non classées. Aucun zéro ne vaut attestation d’absence d’activité. Les lacunes restent signalées, y compris dans le PDF. Les sauvegardes ont un auteur, une date et un numéro de version avec protection contre l'écrasement concurrent. Chaque nouvel enregistrement conserve un instantané des cadres, totaux, sources et réserves dans le journal de l'organisme. L'historique permet de réexporter le PDF de cette version, sans reprendre les données actuelles. Lors de la première modification d'un ancien dossier, son dernier état sauvegardé est conservé ; les versions déjà écrasées avant cette fonctionnalité ne peuvent pas être reconstituées. Ce journal applicatif ne remplace ni un archivage probant ni la preuve du dépôt MAF.

## Calculs déclaratifs

- C2 = Ca à Ch. Total C = C1 + C2 + C3 à C11, sans double comptage de Ca à Ch.
- D : total comptable et sous-rubriques salaires / achats et honoraires.
- E : effectifs des intervenants internes / externes et heures dispensées.
- F1, F3 et F4 : égalité des effectifs et des heures-stagiaires.
- F2 est inclus dans F1.
- Les niveaux RNCP détaillent F3a, sans les ajouter au total F3.
- F4 comporte cinq codes NSF distincts au maximum, puis une ligne Autres.
- G sépare la sous-traitance reçue, exclue de F et rattachée à C10.
- Les montants financiers sont saisis en euros HT entiers, conformément à l'arrondi demandé par la notice. Les pourcentages et les heures sont également entiers.

Les catégories de financeurs, le statut des publics, la classification RNCP/RS/NSF et les charges ne sont pas déduits arbitrairement des champs existants. Ils sont renseignés après rapprochement, en conservant les références des justificatifs dans les notes.

## Repères de rapprochement

Les repères de facturation utilisent les factures EUR non annulées par date d'émission, même sans session dans la période. Les avoirs sont présentés séparément en TTC car le modèle ne fournit pas directement un HT fiable. Les produits à recevoir, constatés d'avance, les autres devises, les activités hors formation et les avoirs HT doivent être rapprochés en comptabilité.

Les repères pédagogiques excluent les sessions brouillon/annulées et les créneaux futurs ou non terminés. Ils utilisent les durées nettes du planning ventilées par date et incluent les sessions à intitulé libre. Les préinscriptions, annulations et absences sont exclues des compteurs d'inscriptions. Les heures planifiées ne sont pas présentées comme des heures effectivement suivies. Les abandons, annulations après réalisation partielle, activités externes, sous-traitance confiée et justificatifs de présence doivent être vérifiés séparément.

## Déploiement

Exécuter la migration `DoctrineMigrations\Version20261008180000` pour créer `bpf_declaration`, puis vider le cache applicatif. Aucun envoi ou dépôt n'est exécuté par la migration ni par le module.


## Génération depuis la plateforme

Le bouton « Générer et enregistrer mon BPF » prend les bornes choisies, inclusives, et n’inclut pas les créneaux futurs. `BpfPrefill` produit les valeurs, les pièces sources et les réserves ; cette photographie est enregistrée avec le dossier. Aucune donnée de production n’est modifiée en consultant la page.

Les correspondances sont des choix validés côté serveur et propres à l’organisme : objectif/NSF par formation, statut par inscription et formateur, financeur par facture, catégorie BPF par catégorie de dépense. Elles sont reprises depuis les 20 derniers dossiers, puis surchargées par le dossier courant. Un dossier existant ne change que sur enregistrement explicite. Le recalcul remplace les anciens calculs inchangés et les cases vides, sans écraser une correction manuelle (même zéro).

- Les participations sont déduites des présences papier ou signatures des stagiaires. Une absence ne génère pas d’heures. Les sessions annulées après un début effectif peuvent conserver leur activité documentée. Les présences partielles sur un créneau comportant une pause non ventilée sont signalées pour correction, sans répartition inventée.
- Les statuts F1 et financeurs/dispositifs C doivent être confirmés explicitement : le financement d’une inscription ne prouve ni son statut (apprenti, agent public, etc.), ni le payeur de chaque facture (reste à charge CPF, subrogation OPCO, financement mixte). Les correspondances confirmées sont réutilisées. Les anciennes correspondances enregistrées avant cet audit restent à vérifier. Une facture mixte n’est pas divisée arbitrairement.
- Le cadre G est séparé de F. Les participants sans inscription sont signalés pour rattachement. Les heures des formateurs sont proposées exclusivement pour les créneaux avec présence documentée, dans la limite de la plus longue durée suivie documentée pour ce créneau. Les autres dates de la même session ne sont pas assimilées à des heures dispensées. Les interventions réelles, présences partielles et coanimations restent à confirmer.
- Les factures EUR non annulées sont agrégées HT, hors débours ; les anciennes factures sans lignes utilisent leur total HT enregistré. Un avoir intégral est déduit au HT de sa facture ; un avoir partiel sans HT documenté est signalé pour ventilation comptable. Les produits constatés d’avance, charges non enregistrées et écritures de clôture restent à rapprocher.
- D se calcule sur les dépenses classées, pas les contrats prévisionnels. La part formation du CA est proposée seulement si toutes les factures EUR nettes sont classées ; elle reste à confirmer avec le CA comptable global.

Les classifications RNCP/RS et NSF ne sont pas inventées à partir d’un titre commercial. Les contrôles de génération sont conservés dans le PDF. Le module prépare la déclaration, il n’effectue aucun dépôt MAF.

## Audit correctif du 8 octobre 2026

- Arrondi pédagogique : allocation des heures entières par plus grands restes, pour conserver le total arrondi d’activité lorsque les mêmes heures sont réparties différemment dans F1, F3 et F4 ; les niveaux RNCP sont arrondis sous contrainte du total F3a. Il s’agit de la méthode technique retenue pour respecter les égalités de la notice, pas d’une méthode imposée par celle-ci.
- Les rubriques non classées sont signalées comme partielles dans la page et dans les cadres du PDF. Les valeurs disponibles sont conservées sans affirmer que les données manquantes valent zéro.
- L’export comporte une annexe avec les pièces/présences prises en compte, les classifications au moment du calcul et sa date. Les corrections manuelles sont conservées dans les cadres précédents.
- Vérifications automatisées : bornes d’exercice, isolation des organismes, CSRF, conservation des corrections, avoir intégral/partiel, présence manquante, séparation F/G et répartition des arrondis. Le rendu PDF est contrôlé sur données fictives.

Limites : l’application n’est pas une comptabilité exhaustive ; la reconnaissance des produits et charges (factures à établir, charges constatées d’avance, paie, écritures de clôture), les heures et statuts non documentés ainsi que les activités hors plateforme nécessitent un rapprochement avec les pièces de l’organisme. La validation administrative finale et le dépôt MAF restent extérieurs au module.
