# Information sur les tarifs et les données — 28 octobre 2026

Périmètre : informations publiques de la page Tarifs, du catalogue d’abonnements,
des CGV et de la politique de confidentialité. Ce relevé décrit les changements
de texte et la preuve d’accord avant souscription ; il ne certifie pas les contrats, les traitements ou l’exploitation.

## Tarifs publiés

- Abonnements : prix issus du catalogue existant, inchangés. Les coûts
  d’encaissement de ce prix sont compris dans l’abonnement ; aucune surcharge
  liée au moyen de paiement n’est ajoutée.
- Connecteur de facturation électronique : tarif standard de 0,03 EUR HT,
  répartition interne 0,01 EUR transmission / 0,01 EUR rémunération WikiFormation /
  0,01 EUR provision pour le traitement du paiement (pas une mesure des frais Stripe réels).
  I.T. For You, uniquement entité propriétaire et SIRET 80813612100023 :
  0,01 EUR HT via Stripe, sans commission WikiFormation.
- Une prise en charge fournisseur est comptée une seule fois, y compris en cas
  de rejet ultérieur. Tests, blocages avant dépôt et réceptions ne sont pas
  décomptés. Regroupement mensuel puis prochaine facture d’abonnement ; une
  échéance annuelle n’est pas transformée en échéance mensuelle.
- Consentements et tarifs historiques conservés ; un ancien accord à 0 ou 2 centimes
  doit être renouvelé avant une nouvelle émission payante.
- Encaissement des factures clients : distinct de la transmission électronique,
  frais du compte Stripe de l’organisme et éventuels frais de service prélevés
  sur sa part, sans supplément ajouté au solde payé par le client.
- Marketplace suspendue : aucune nouvelle vente ni consommation SUPER PDP de
  ce parcours annoncée comme disponible. Les achats et paiements historiques,
  leurs frais, commissions, remboursements et reprises restent distincts.

La répartition interne ci-dessus est la politique commerciale de WikiFormation,
pas une synchronisation garantie avec l’ensemble des prix, volumes, minimums,
KYB, coûts Connect ou autres frais futurs du fournisseur. Aucune majoration
de souscription ni nouveau prix Stripe n’est créé par cette mise à jour.

## Accords et preuves

- Souscription : case CGV non précochée, contrôle serveur de la version et de
  l’empreinte du texte effectivement affiché. Un changement du document ou du
  catalogue impose une nouvelle lecture. Avant ouverture du Checkout Stripe,
  un AuditLog conserve le texte des 12 clauses, son empreinte, sa version,
  le titulaire, l’organisme, la date et le prix HT du plan choisi. Cette preuve
  n’atteste ni le paiement ni l’activation de la transmission électronique.
- Vendeurs tiers : la politique de frais de traitement est versionnée et
  affichée avant leur accord. Un AuditLog distinct conserve son texte, la
  commission et les conditions configurées. Une ancienne acceptation ne
  permet pas de créer de nouvelles ventes avec les frais à la charge du vendeur.
  Le contrôle précède publication, activation Connect et Checkout. Les preuves
  sont recopiées dans la commande ; les règlements/remboursements historiques
  continuent à utiliser les conditions figées de leur commande.
- Les frais Stripe non rapprochés sont affichés « En cours de rapprochement »,
  au lieu de présenter provisoirement zéro comme un montant définitif.

## Information sur les données

La notice mentionne maintenant SUPER PDP et les flux de facturation, leurs
données, finalités, destinataires et critères de conservation. Elle distingue
l’accord tarifaire contractuel d’un consentement général au traitement des
données. Elle explique les consultations de configuration Stripe et conserve
l’information sur les droits et les rôles plateforme / organisme.

Les contrats de sous-traitance, la liste effective des sous-traitants ultérieurs,
les lieux de traitement, les garanties de transfert, le calendrier de conservation
et sa mise en œuvre demeurent à documenter dans les pièces opérationnelles.
Un lien vers la notice d’un fournisseur ou un changement de texte public ne
remplace ni l’analyse des rôles ni un accord conforme à l’article 28 du RGPD.
Les badges présentant la page comme une preuve de « conformité RGPD » ont été
remplacés par une présentation de protection des données et de transparence.

## Sources officielles consultées

- Code monétaire et financier, article L112-12 :
  https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000035430410
- CNIL, information et transparence :
  https://www.cnil.fr/fr/conformite-rgpd-information-des-personnes-et-transparence
- CNIL, identification des rôles :
  https://www.cnil.fr/fr/rgpd-comment-bien-identifier-son-role
- Stripe, politique de confidentialité : https://stripe.com/fr/privacy
- SUPER PDP, lien données personnelles publié dans le pied de page :
  https://www.superpdp.tech/legal/16/

## Vérifications

Validation syntaxique des templates et du conteneur : réussie.
Suites BillingCatalogue, Marketplace, MarketplaceProcessingFees et MarketplaceCartSeller :
85 tests, 706 assertions réussis ; bases SQLite en mémoire et Stripe simulé.
Aucune modification de prix distant, aucun paiement ni envoi de données réelles.
