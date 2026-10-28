# Vérification Stripe et émissions électroniques — 28 octobre 2026

## Contrôle réalisé par le code

Le service `StripeSubscriptionPaymentReadiness` vérifie l’état distant à chaque appel, uniquement par des lectures Stripe. Il ne crée aucun paiement, aucune facture Stripe, aucun mandat et aucun transfert.

Il exige une clé de production, les bons identifiants d’abonnement et de client, le mode live, la devise EUR, un abonnement actif ou un essai non expiré, une période vérifiable et le prélèvement automatique sans pause. Une demande de résiliation future ne vaut pas une fin d’accès déjà effective : `canceled_at` n’est donc pas utilisé comme date de fin.

Le client Stripe doit porter la métadonnée `entite_id` correspondant exactement à l’organisme de l’abonnement. Le service de création des clients Stripe renseigne cette valeur côté serveur. Un client historique sans ce rattachement doit être rapproché explicitement par l’administrateur ; aucun lien n’est deviné à partir du nom ou de l’adresse e-mail et aucune métadonnée distante n’est modifiée automatiquement.

Le traitement TVA doit être activé automatiquement ou configuré avec des taux explicites hors taxes vérifiables. Pour le paiement, le contrôle respecte la priorité Stripe : moyen de paiement de l’abonnement, ancienne source de l’abonnement, moyen de paiement du client puis ancienne source du client. Les anciennes sources sont refusées avec une demande de mise à jour ; elles ne sont pas remplacées silencieusement par un moyen de paiement moins prioritaire.

Une carte doit appartenir au bon client, être en production et ne pas être expirée. Un prélèvement SEPA doit avoir un mandat de production actif, réutilisable et associé au même moyen de paiement. La recherche du mandat est bornée aux dix dernières préparations du moyen de paiement concerné, puis aux dix derniers paiements du client. Si elle ne permet pas de confirmer le mandat, le contrôle refuse l’émission et invite à confirmer à nouveau le prélèvement ou à enregistrer une carte. Un mandat ancien peut donc demander une nouvelle confirmation, sans appel d’écriture automatique.

Un type de paiement exclu des paramètres de l’abonnement et une authentification en attente sont refusés. Une panne ou une réponse Stripe inexploitable ferme le contrôle avec un message sans secret ni réponse technique distante. Aucun résultat n’est conservé dans un cache persistant.

Chaque lecture utilisant le transport cURL Stripe est limitée à dix secondes au total et trois secondes pour la connexion, sans augmenter des limites déjà plus strictes. Les paramètres d’origine du transport partagé sont restaurés dans tous les cas, y compris après une erreur ; les autres opérations Stripe conservent donc leurs propres réglages. Ces limites s’appliquent à chaque requête, pas à la somme de toutes les vérifications d’un parcours.

Ce contrôle prouve une configuration vérifiée au moment de l’appel. Il ne garantit ni les fonds disponibles, ni l’encaissement futur, ni l’absence de contestation. Il ne certifie pas l’application et ne remplace pas le suivi des dépôts, rejets, règlements et obligations applicables.

## Tarif et part I.T. For You

La politique serveur attribue 0,03 € HT par émission confirmée aux organismes ordinaires : 0,01 € de coût fournisseur de référence, 0,01 € de part plateforme et 0,01 € de provision pour le traitement de l’encaissement. Ce tarif de service est identique quel que soit le moyen de paiement accepté ; la provision ne constitue pas le montant réel de frais Stripe pour chaque émission. I.T. For You, identifié simultanément par l’entité 1 et le SIRET `80813612100023`, conserve un tarif de 0,01 € HT, sans part plateforme ni provision interne. Ses propres coûts Stripe restent supportés par l’exploitant. Un nom d’affichage identique ou la copie du seul SIRET sur une autre entité ne donne pas accès à ce tarif. Les deux tarifs nécessitent désormais un abonnement Stripe et un consentement au tarif courant.

`EInvoiceTariff::allocation()` centralise cette ventilation (version 2, avec `paymentProcessingReserveCents`). Elle est figée dans les conditions de la transmission avant le dépôt, puis lisible sur la consommation confirmée. Le coût de référence ne constitue pas une vérification du contrat ou de la facture réelle du fournisseur. Les conditions des anciennes tentatives, notamment à 0,02 €, restent conservées, sans nouvelle tarification rétroactive. Un ancien accord à 0,02 € ne permet pas une nouvelle émission à 0,03 € : une nouvelle acceptation est nécessaire.

La ligne mensuelle Stripe facture le prix total à l’organisme, hors taxes et sans remise. Elle est ajoutée à son prochain abonnement. Le prix total est encaissé par le compte Stripe plateforme ; la part de 0,01 € n’appelle donc pas un transfert Connect supplémentaire vers ce même compte. Un lot ajouté à Stripe reste une créance, pas une preuve d’encaissement. Le paiement effectif doit être rapproché de la facture Stripe ; la dette fournisseur se suit séparément.

La cohérence du raccordement logiciel est vérifiable sans lire de secret : `config/services.yaml` relie `stripe.secret_key` à `STRIPE_SECRET_KEY`. `StripeBillingService`, `StripeUsageInvoiceGateway`, `StripeSubscriptionPaymentReadiness` et `StripeClientFactory` utilisent ce même paramètre. La passerelle marketplace utilise cette factory. Cela ne prouve pas à qui appartient le compte réellement configuré en production : ce point doit être contrôlé dans l’environnement de déploiement et dans Stripe.

## Marketplace : suspension tant que son raccordement est incomplet

Les documents marketplace sont des `Marketplace\Invoice`, distincts des factures de formation. Ils ne passent pas dans le pipeline électronique actuel. Les nouvelles ventes et nouvelles factures marketplace restent donc bloquées ; un résultat de paiement déjà engagé doit être conservé et signalé comme document à traiter, sans inventer une facture ni refacturer une émission inexistante. La politique 0,03 €/0,01 € est réutilisable pour ce futur flux, mais aucune consommation électronique marketplace ne doit être annoncée comme enregistrée ou encaissée tant que ce raccordement n’existe pas.

Les travaux nécessaires à une réouverture sont identifiés :

1. Associer explicitement le vendeur à l’entité légale autorisée à émettre. Cette association doit être vérifiée par les droits et le SIRET, puis figée dans la commande ; le cours ou son propriétaire technique ne permettent pas d’inférer l’émetteur.
2. Compléter les coordonnées structurées, dont la ville, les conditions fiscales et les données d’émission. Conserver un instantané immuable de ces données avec son empreinte, et l’entité émettrice sur chaque document. Les anciennes pièces incomplètes restent à traiter explicitement.
3. Étendre la transmission avec deux cibles exclusives : facture de formation ou facture marketplace, assorties de contraintes d’unicité. Créer une facture de formation miroir provoquerait deux documents comptables et n’est pas une solution.
4. Construire un adaptateur marketplace vers le format SUPER PDP. Une vente déjà payée doit fournir `totals.paid_amount` et un `totals.amount_due_for_payment` cohérent. L’envoi ne doit pas réclamer une seconde fois le total. La compensation des commissions, les avoirs et les règlements nécessitent leur propre traitement explicite.
5. Réutiliser le contrôle Stripe, le consentement, le journal de transmission et `EInvoiceUsage` uniquement après confirmation fournisseur. Couvrir la reprise après coupure, le rapprochement, les doublons et l’isolation entre vendeurs par des tests.
6. Compléter et recetter les flux hors périmètre actuel : particuliers/e-reporting, exonérations, international, secteur public, avoirs et événements d’encaissement. La documentation SUPER PDP expose davantage de fonctions que le connecteur actuellement livré ; la disponibilité d’une API ne constitue pas son intégration.

## Vérification et limites de déploiement

Les tests de ce service utilisent un transport Stripe simulé et vérifient que toutes les requêtes sont des GET. Aucun compte Stripe réel, paiement, transfert Connect, invoice item ou dépôt fiscal n’a été utilisé pour cette intervention.

Le serveur de production, les contrats, le compte destinataire des encaissements, les autorisations OAuth multi-organismes, les tâches planifiées de synchronisation et de regroupement, ainsi que les moyens de paiement réels restent à vérifier sur cet environnement. Une indisponibilité du fournisseur peut bloquer de nouvelles émissions ; les documents historiques restent consultables.

Références consultées le 28 octobre 2026 : [abonnement Stripe](https://docs.stripe.com/api/subscriptions/object), [types de paiement de l’abonnement](https://docs.stripe.com/billing/subscriptions/payment-methods-setting), [moyens de paiement](https://docs.stripe.com/api/payment_methods/object), [taux de taxe](https://docs.stripe.com/api/tax_rates/object), [mandats SEPA](https://docs.stripe.com/payments/sepa-debit), [API SUPER PDP](https://www.superpdp.tech/openapi/).
