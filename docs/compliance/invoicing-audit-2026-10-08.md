# Facturation : audit ciblé du 8 octobre 2026

Périmètre : factures clients, conversion des devis, création commerciale et automatique, PDF, encaissements Stripe, contrôles d’organisme. Contrôle du code et tests sur bases jetables ; aucun paiement, envoi, effacement de données ou déploiement réel. Ce document décrit des corrections applicatives et leurs limites, sans attester une conformité comptable complète ni un agrément de plateforme.

## Références officielles

- [Service Public Entreprendre — facturation](https://entreprendre.service-public.gouv.fr/vosdroits/F23208), vérifié le 7 août 2026 : une facture déjà émise ne se modifie pas directement. Sa correction passe par une pièce identifiée ; un avoir permet notamment de corriger après paiement. Les factures doivent être conservées.
- [Service Public Entreprendre — mentions des factures](https://entreprendre.service-public.gouv.fr/vosdroits/F31808), vérifié le 11 août 2026 : identité des parties, références, dates, détail de la prestation, montants et TVA, conditions de règlement et mentions adaptées à la situation fiscale et au client.
- [Ministère de l’Économie — mentions obligatoires](https://www.economie.gouv.fr/entreprises/gerer-son-entreprise-au-quotidien/gerer-sa-comptabilite-et-ses-demarches/mentions-obligatoires-dune-facture-tout-savoir) : numéro unique reposant sur une séquence chronologique et continue.
- [Code monétaire et financier, article L112-12](https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000035430410) : le bénéficiaire ne peut pas appliquer de frais pour l’utilisation d’un instrument de paiement donné.

La facturation électronique réglementaire et le raccordement à une plateforme agréée sont examinés séparément dans [l’audit général](../qualite/audit-2026-10-08.md). Un PDF envoyé par mail ou un paiement Stripe ne constitue pas cette intégration.

## Corrections effectuées

### Conservation des factures émises

Le modèle `Facture` ne possède pas d’état brouillon : les lignes enregistrées sont immédiatement numérotées et émises. Pourtant, les anciennes routes permettaient de modifier leur date, statut ou montant, et de supprimer une facture impayée. Une facture payée pouvait être simplement annulée.

Les routes historiques d’édition et suppression sont désormais inopérantes sur ces pièces, même par une requête directe valide. Les factures, lignes et paiements sont conservés. L’interface oriente vers l’écran existant de création d’avoir ; il faut y sélectionner la facture d’origine. La création n’accepte plus un statut « Payé » ou « Annulé » arbitraire. Un PDF acquitté et un nouveau paiement sont refusés pour une facture annulée.

### Calcul commun et montants enregistrés

La création manuelle ignorait la remise globale dans les montants enregistrés. Certains getters conservaient la TVA avant remise ; le PDF et les tableaux pouvaient encore appliquer d’autres arrondis.

Les nouvelles factures manuelles, commerciales, issues d’un devis ou d’une automatisation passent désormais par `InvoiceTotals::finalizeNewInvoice`. Le calcul en centimes répartit intégralement la remise, calcule la TVA après réduction et conserve un instantané versionné dans `meta.invoice_totals`, avec le détail par ligne et par taux. Les montants stockés, getters de paiement et de HT hors débours, PDF, tableau des factures et KPI relisent cette même base. Le formulaire a été aligné sur les mêmes règles d’arrondi.

Exemples vérifiés : 100 € HT à 20 % avec 10 % de remise donnent 90 € HT, 18 € de TVA et 108 € TTC ; une remise fixe de 1 € répartie entre 10 € à 20 % et 10 € à 5,5 % donne 19 € HT et 2,42 € de TVA. Les centimes de remise restent intégralement affectés, même sur cent petites lignes.

Le numéro automatique de secours utilise l’année d’émission. Les nouvelles factures administratives sont limitées à l’euro, devise réellement prise en charge par leurs documents. Aucun ancien numéro, montant ou instantané n’a été reconstruit ou réécrit.

### Émission atomique, identité figée et conditions réutilisables

Les quatre chemins de création passent maintenant par `InvoiceIssuer`. La réservation du numéro, l’instantané complet et la création de la pièce sont validés dans la même transaction. La séquence utilise un upsert atomique, y compris pour la première facture d’un exercice ; une erreur d’enregistrement restitue le numéro. Un verrou sur l’organisme sérialise les émissions et interdit une date antérieure à la dernière facture. Les numéros et données des pièces déjà enregistrées ne sont pas réécrits.

Les nouvelles pièces conservent dans `meta.invoice_snapshot` les identités et adresses de l’émetteur et du destinataire, leurs identifiants fiscaux, les références, dates, prestations, remises, taxes, modalités de règlement et le logo local. Une empreinte SHA-256 permet de détecter une modification accidentelle de cet instantané. Les PDF ordinaires, reçus acquittés et données de préparation électronique relisent ces informations ; une modification ultérieure d’une fiche client, de l’organisme ou d’une ligne ne change plus leur contenu émis. Une empreinte invalide bloque la génération au lieu de revenir silencieusement aux fiches courantes. Cette empreinte interne n’est pas une signature électronique qualifiée ni un archivage probant externe.

La page de préférences de facturation de chaque organisme centralise ses conditions de règlement, délai d’échéance, identité juridique, mentions d’escompte, pénalités, indemnité B2B et situation fiscale. Ces valeurs ne sont réutilisées qu’après confirmation explicite. L’échéance découle alors du délai validé. La date de prestation n’est jamais inventée : elle peut être préparée dans le devis puis reprise à la conversion, ou indiquée lors de la saisie de la facture. Le régime d’exigibilité est un choix explicite encaissements/débits, sans assimiler une case non cochée à une confirmation.

`InvoiceReadiness` bloque **l’émission avant attribution du numéro** si les identités, adresses, dates et mentions nécessaires manquent. Les lignes sans TVA exigent une catégorie et un motif explicites ; aucune exonération liée à la seule activité de formation n’est présumée. L’automatisation signale les informations manquantes avant de démarrer sa transaction. Les formulaires permettent de compléter les informations plutôt que de produire une facture figée incomplète. Le contrôle de syntaxe et de présence ne certifie pas l’exactitude du régime fiscal, des clauses ou de l’identité déclarée.

La migration `Version20261008201000` ajoute les préférences de facturation en JSON. Elle est livrée, non appliquée sur les données réelles dans cette passe.

### Paiement du solde et commission

Deux parcours Stripe demandaient des montants différents : le gestionnaire historique pouvait redemander le TTC intégral après un paiement partiel, tandis que l’autre parcours ignorait les avoirs. Les deux utilisent désormais le reste dû commun : TTC moins encaissements moins avoirs, sans déduction répétée. Les cas 120 € moins 50 € payés = 70 €, 120 € moins 20 € d’avoir = 100 €, et 120 € moins 50 € payés et 20 € d’avoir = 50 € sont couverts. Une facture intégralement créditée ne provoque aucun appel de paiement et ne devient pas acquittée du seul fait de l’avoir. Après un avoir partiel de 20 € et un encaissement de 100 €, la facture de 120 € est correctement soldée.

La réutilisation d’un lien récent vérifie à la fois son principal et son montant total demandé. Un ancien lien à 120 € après modification du solde, ou un ancien lien à 100 € majoré de 3 € de frais, est expiré chez Stripe avant son remplacement. Si cette expiration échoue, aucun nouveau lien n’est créé et l’interface explique pourquoi le remplacement n’est pas disponible ; l’ancien lien n’est pas renvoyé.

Le gestionnaire historique ajoutait également une ligne de frais au prix payé par le client. La commission configurée est désormais prélevée sur l’encaissement de l’organisme, comme dans le parcours actuel, sans supplément au client. Les paramètres sont conservés et leurs libellés précisent cette règle. Une commission ne peut pas dépasser le montant de l’encaissement.

### Autorisations et documents

Le parcours administratif de paiement exige maintenant la permission de gestion des factures de l’organisme. La modale d’envoi depuis une fiche utilisateur vérifie que cette personne est bien destinataire ou stagiaire d’une inscription liée de ce même organisme. L’envoi exige un jeton CSRF avant toute écriture de journal ou transmission.

Le numéro de TVA de l’émetteur figure sur le PDF même sans coordonnées bancaires. Une date de prestation renseignée dans les métadonnées apparaît aussi sans inscription liée. Le contrôle visuel d’un exemple fictif à plusieurs taux confirme la lisibilité du PDF A4, sans débordement.

## Limites à résoudre avant de revendiquer un remplacement de la comptabilité

1. **Historique à rapprocher.** Les anciennes factures sans instantané conservent leur comportement historique. Une remise ancienne peut donc révéler une différence entre le montant stocké et un montant calculé. Il faut rapprocher ces pièces avec les documents réellement émis et procéder à une correction tracée, sans recalcul silencieux.
2. **Exploitation des séquences.** Les nouveaux chemins applicatifs sont transactionnels et contrôlent la chronologie. Les scripts d’import externes doivent respecter ce service ou faire l’objet d’un rapprochement dédié. Le comportement sous charge de plusieurs workers sur le serveur MySQL de production n’a pas été testé ; les régressions utilisent SQLite et contrôlent le rollback réel.
3. **Qualification fiscale et cas particuliers.** Les mentions principales sont structurées et contrôlées à l’émission. L’organisme doit confirmer ses informations et clauses. Les opérations internationales, régimes particuliers, taxes ou catégories mixtes nécessitent une validation métier spécifique ; le raccordement PA applique en plus son propre périmètre documenté. Le contrôle ne vérifie pas l’existence réelle d’un numéro de TVA dans VIES ni le bien-fondé juridique du texte renseigné.
4. **Débours et avoirs.** Le calcul préserve la règle existante de ventilation des lignes marquées en débours. Leur qualification fiscale et leurs justificatifs restent à contrôler ; une simple case ne démontre pas un débours. Le modèle d’avoir reste global en TTC, sans ventilation détaillée de TVA par ligne ; tous les états comptables ne sont pas encore rapprochés des avoirs.
5. **Archivage.** L’instantané complet fige désormais les informations et visuels des nouvelles pièces, mais pas les octets du PDF produit. Une évolution future du modèle pourrait changer sa mise en page. Une politique d’archivage probant, de conservation et de sauvegardes des documents réellement transmis, ainsi qu’un journal comptable complet, restent à organiser. Les anciens documents sans instantané ne sont pas reconstructibles de manière fiable à partir des seules fiches actuelles.
6. **Périmètre comptable.** Aucune comptabilité générale complète, clôture, FEC, rapprochement bancaire ou conformité de logiciel comptable/caisse n’est certifiée par ces correctifs. Les bases, accès et sauvegardes de production n’ont pas été audités dans cette passe.
7. **Cycle de paiement complet.** Les tests remplacent entièrement le transport Stripe ; aucun encaissement réel n’a été effectué. La correction d’un lien intervient à sa réémission dans le parcours qui réutilise les liens récents. L’invalidation immédiate de tous les anciens liens externes lors de chaque paiement ou avoir, les courses concurrentes et l’ensemble des notifications Stripe restent à traiter dans un audit complet du cycle de paiement.

## Vérifications

Tests PHPUnit sur SQLite en mémoire et transport de messagerie remplacé, contrôles Twig/PHP, vérification de trois cas du calcul de formulaire JavaScript et rendu visuel d’un PDF fictif. Les tests couvrent les refus d’édition/suppression, la conservation des pièces et paiements, les frontières d’organisme, les droits/CSRF, la création manuelle, la conversion de devis, les montants du PDF et des tableaux, les centimes de remise et les taux multiples. Les parcours historiques sans lignes sont conservés.

Dernière validation ciblée : **29 tests / 217 assertions** (`InvoiceIntegrityTest`, `InvoiceTotalsTest`, `WorkflowBillingTest`). Les appels Stripe sont simulés : solde après paiements/avoirs, commission sans surcharge, expiration des anciens liens, refus de créer une deuxième session lorsque l’expiration échoue, retour de paiement et absence de double enregistrement. Une passe complémentaire du listener de numéro, navigation financière, délivrance automatique et workflows a passé **36 tests / 309 assertions** avant les dernières corrections Stripe. Après intégration, la suite complète a réussi : **496 tests / 3 628 assertions** ; les 388 modèles Twig et le conteneur Symfony ont également passé leurs contrôles.

Passe d’émission et préférences : **69 tests / 495 assertions** (`InvoiceIssuanceTest`, `InvoiceIntegrityTest`, `InvoiceTotalsTest`, `WorkflowBillingTest`, `DevisConventionTest`, `FinanceNavigationTest`). Cas supplémentaires : rollback du numéro après échec réel de flush, séquence et rejet d’antidatation, absence de numéro consommé lorsque les mentions manquent, PDF et reçu identiques après modification des fiches, refus d’empreinte altérée, absence de régime fiscal deviné, exonération explicite acceptée, enregistrement des préférences protégé par CSRF et confirmation des valeurs, automatisation bloquée avant transaction si la date de prestation manque. Les cinq modèles concernés et le conteneur Symfony ont passé leurs contrôles ; le PDF fictif A4 a été rendu et inspecté visuellement avec les nouvelles mentions.
