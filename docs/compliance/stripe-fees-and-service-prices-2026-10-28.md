# Prix du service et frais Stripe — 28 octobre 2026

Décisions explicites de l’exploitant : conserver les prix des abonnements avec leurs coûts d’encaissement inclus ; faire supporter aux organismes les frais de leurs propres encaissements ; augmenter le prix des émissions électroniques pour y intégrer une provision d’encaissement. Aucun compte Stripe réel n’a été modifié pendant cette intervention.

## Trois flux distincts

- Abonnement WikiFormation : prix mensuels inchangés. Le compte plateforme paie Stripe, sans ligne de supplément liée au moyen de paiement. Les frais sont absorbés dans le prix annoncé.
- Factures clients des organismes : nouveaux paiements directs sur leur compte Connect, solde client inchangé. `DirectChargeFeePolicy` vérifie le compte distant, le rattachement par `metadata.entite_id`, l’activation et `controller.fees.payer`. Valeurs acceptées : `account`, `application_express`, `application_custom`, pour les frais standards de traitement. La commission application éventuelle reste séparée ; le code ne lui ajoute pas une seconde estimation de frais Stripe. Les paramètres des tentatives historiques sont conservés pour ne pas créer un deuxième paiement.
- Marketplace : nouveaux achats tiers figent les frais de traitement à charge du vendeur ; ventes propres à charge plateforme. Les montants réellement retournés et validés par Stripe déterminent les versements. Voir `marketplace-stripe-processing-fees-2026-10-28.md`. Les nouvelles ventes restent suspendues jusqu’au raccordement de leur facturation électronique.

Les valeurs Express/Custom ne garantissent pas que les coûts de produits annexes, Connect, Tax, Billing ou Interchange Plus soient supportés par le compte connecté. Ils nécessitent leur propre contrôle contractuel et rapprochement, et ne sont pas présentés comme automatiquement refacturés. Le choix du payeur Stripe se fixe à la création du compte : aucun compte existant n’a été migré ni reconfiguré automatiquement.

## Tarif de l’émission électronique

Tarif standard : **0,03 € HT** par émission confirmée en production, comprenant 0,01 € de coût SUPER PDP de référence, 0,01 € de part plateforme et 0,01 € de provision d’encaissement. Exception propriétaire : **0,01 € HT**, sans marge ni provision, strictement entité 1 et SIRET 80813612100023. Les coûts Stripe de cette exception restent à la charge de l’exploitant.

Il s’agit d’un prix de service unique, pas d’un supplément selon la carte ou le moyen de paiement. La provision n’est ni une mesure des frais réellement facturés par Stripe ni une garantie universelle de marge. Le coût fournisseur de référence de 0,01 € est celui indiqué par l’exploitant ; il n’a pas été confirmé par lecture de son contrat SUPER PDP.

Le code regroupe les consommations sur la prochaine facture d’abonnement : il ne crée pas un paiement de 0,03 € pour chaque émission. Les frais fixes d’un règlement ne doivent donc pas être imputés autant de fois qu’il y a d’émissions. Les coûts de base du règlement de l’abonnement sont déjà inclus dans son prix, selon la décision de l’exploitant.

Exemple indicatif, pas un tarif bancaire garanti : 100 émissions = 3,00 € HT, soit 3,60 € TTC avec une TVA de 20 %. Un coût proportionnel combinant 1,5 % de traitement et 0,7 % de Billing représente environ 0,08 € sur ce montant supplémentaire, avant éventuels autres frais et arrondis, pour 1,00 € de provision. Les frais fixes du règlement mensuel complet ne sont pas ajoutés une seconde fois dans cet exemple. Le contrat Stripe, les cartes, taxes, minimums, incidents et produits activés peuvent modifier ce résultat.

Le ledger accepte les anciens tarifs de 0,01/0,02 € pour les tentatives déjà engagées et 0,03 € pour les nouvelles. Les prix restent figés avant transmission. Une ancienne acceptation à 0,02 € n’autorise pas le nouveau tarif ; le titulaire doit le confirmer avant une nouvelle émission. Aucun lot historique n’est recalculé.

## Références et limites

- Article L112-12 du Code monétaire et financier : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000035430410
- Tarifs publics Stripe France consultés le 28/10/2026 : carte standard EEE 1,5 % + 0,25 € par paiement, autres cartes et services selon barème : https://stripe.com/fr/pricing
- Billing à l’usage : 0,7 % du volume traité selon l’offre publique, sans preuve du contrat de ce compte : https://stripe.com/fr/billing/pricing
- Responsabilité des frais directs : https://docs.stripe.com/connect/direct-charges-fee-payer-behavior

La présence d’un invoice item ne prouve pas son paiement. Vérifier en production les tâches de regroupement, les factures Stripe finalisées/payées, les contrats et le compte bancaire bénéficiaire. Aucun prélèvement, transfert, courrier client ou déploiement n’a été exécuté par ces modifications locales.

## Validation finale locale

- Suite transversale facturation, émissions, marketplace et confidentialité : 513 tests, 3 191 assertions réussis.
- Après le dernier verrou du changement d’offre et du portail interne : suite Billing, contrat interne, catalogue, encaissements et filtres, 141 tests, 917 assertions réussis. Ces suites se recouvrent : leurs totaux ne s’additionnent pas.
- 6 tests JavaScript de confidentialité, 17 templates Twig, routes YAML et conteneur Symfony validés ; syntaxe PHP et `git diff --check` sans erreur.

Les échanges Stripe sont simulés et les données des tests sont isolées. La migration `Version20261028120000` doit précéder l’activation du code et du webhook. Les règles de téléchargement des supports doivent être déployées et vérifiées sur le serveur réel. Les limites de conformité d’exploitation restent décrites dans `privacy-followup-2026-10-28.txt`.

L’exploitant confirme qu’aucun autre organisme n’a encore émis de facture. Aucun message d’annonce ni e-mail d’augmentation n’est prévu ; le tarif reste affiché dans l’offre, les CGV et le consentement d’activation.
