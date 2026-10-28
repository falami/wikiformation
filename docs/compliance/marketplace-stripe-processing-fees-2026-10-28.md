# Frais de traitement Stripe de la Marketplace — 28 octobre 2026

## Politique et périmètre

Les nouvelles commandes d’un vendeur tiers figent `feesPayer=seller` : les frais de traitement Stripe constatés sont déduits de ses recettes, en plus de la commission TTC. Le prix TTC payé par l’acheteur n’est pas majoré. Les ventes propres de la plateforme figent `feesPayer=platform`, sans transfert Connect à elle-même. Un ancien paramètre d’administration `feesPayer=platform` ne change pas cette règle pour les nouvelles commandes ; les anciennes commandes conservent leur choix contractuel enregistré.

Le verrou `MarketplaceInvoiceReadiness` reste inchangé : les nouveaux paiements demeurent suspendus tant que la facturation électronique des vendeurs, commissions et avoirs n’est pas raccordée. Ce correctif ne réactive aucun paiement.

## Vérification avant versement

`Payments::settle()` utilise le même instantané Stripe pour connaître le paiement, ses frais et la disponibilité des fonds. Le deuxième GET qui pouvait découvrir les frais après le calcul du versement a été supprimé. Sont contrôlés : identité de la charge, identité stable de la balance transaction, source correspondant exactement à la charge, devise EUR, montant brut identique à la commande, frais entiers non négatifs, égalité `net = montant - frais`, statut et date de disponibilité.

Un montant de frais réellement nul est accepté. Une absence de balance transaction n’est pas interprétée comme zéro. Des frais supérieurs aux recettes ne produisent ni reversement négatif ni débit supplémentaire du client. Le reversement est plafonné aux recettes restantes ; une éventuelle dette non recouvrée du vendeur nécessite une gestion distincte.

L’état est conservé dans `Purchase.consent.stripe_processing_fees` : `pending`, `reconciliation_required` ou `verified`, avec l’horodatage du contrôle et le dernier instantané vérifié (`lastVerified`). Il ne contient pas de données de carte. Le paiement confirmé, ses remboursements et les droits d’accès restent rapprochés même si les frais sont en attente ou incohérents. Aucun nouveau versement n’est alors autorisé. Le rapprochement des transferts déjà exécutés est conservé ; un remboursement total ou un litige permet encore de reprendre les sommes déjà transférées.

Un changement du montant réel des frais sur la même transaction ajuste uniquement la différence lors du prochain rapprochement, avec les protections de reprise existantes. Un changement d’identité de transaction bloque le versement et nécessite un rapprochement administratif.

## Limites à présenter explicitement

Ce mécanisme traite les frais présents sur la balance transaction du paiement. Il ne prétend pas récupérer tous les frais Stripe : IC+, Connect, Billing, Tax, frais de litige, frais facturés séparément et corrections par écritures distinctes nécessitent les rapports détaillés et un registre d’allocation supplémentaire. Une correction sur une écriture de remboursement distincte n’est pas automatiquement réaffectée par ce correctif. Un relevé à zéro en tarification IC+ ne démontre pas l’absence de frais externes au relevé.

Les frais d’abonnement WikiFormation et les frais de paiement des factures clients relèvent d’autres circuits. Les frais déjà débités directement au compte Connect d’un organisme ne doivent pas lui être refacturés une seconde fois. Les écrans doivent distinguer des frais non disponibles d’un montant nul vérifié.

Sources techniques Stripe : [disponibilité asynchrone des frais](https://docs.stripe.com/expand/use-cases), [balance transactions](https://docs.stripe.com/api/balance_transactions/object), [rapport détaillé des frais](https://docs.stripe.com/reports/all-fees).

## Validation

Tests isolés SQLite, passerelle Stripe simulée : frais retardés, absents, incohérents, nuls, modifiés et supérieurs au montant ; identité et devise étrangères ; disponibilité future ; rejeux et reprise de la seule différence ; conservation des anciens accords ; remboursement total malgré des frais temporairement absents. La suite Marketplace existante vérifie aussi la politique des nouvelles commandes, les ventes propres et la suspension de facturation. Aucun paiement, transfert ou appel Stripe réel n’a été exécuté pour cette validation.
