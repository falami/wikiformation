# Vérification du parcours Stripe — 21 octobre 2026

> Mise à jour tarifaire du 28 octobre 2026 : tarif standard 0,03 € HT
> (transmission 0,01 + service 0,01 + provision de traitement 0,01), sans surcharge
> liée au moyen de paiement. Tarif propriétaire 0,01 € HT inchangé ; frais de
> paiement absorbés par l’exploitant. Les anciens tarifs figés sont conservés.


## Conclusion
Le parcours payant est implémenté et les tests locaux passent : 37 tests, 233
assertions (paramètres, runner, consommation, passerelle Stripe et webhook).
Les appels externes sont simulés. Aucun paiement ni facture réelle n’a été créé.
La configuration et l’exécution sur le serveur de production restent à vérifier.

## Parcours d’un organisme payant
1. Souscrire depuis le Checkout de WikiFormation. Le client Stripe est enregistré
   sur l’abonnement local avant la redirection ; le webhook synchronise ensuite
   l’identifiant de souscription, son statut et sa période.
2. Configurer dans Stripe le webhook de souscription :
   https://wikiformation.fr/fr/stripe/webhook
   Événements traités : checkout.session.completed,
   customer.subscription.created, customer.subscription.updated,
   customer.subscription.deleted. Le secret de signature de cet endpoint doit
   correspondre à STRIPE_WEBHOOK_SECRET. Ne pas confondre avec le webhook des
   paiements de factures de formation /fr/webhooks/stripe.
3. Connecter le bon organisme SuperPDP en production. Vérifier avec SuperPDP
   que les identifiants OAuth autorisent les autres organismes : l’application
   créée dans les captures est restreinte à POPOT JEROEN. La présence du code
   multi-organismes dans WikiFormation ne prouve pas cette autorisation distante.
4. Accepter 0,03 EUR HT par transmission (tarif I.T. For You : 0,01 EUR HT via
   Stripe, sans commission WikiFormation, depuis le 28 octobre 2026). L’activation exige actuellement un
   abonnement local actif, non expiré, avec identifiants cus_ et sub_.
5. Exécuter régulièrement app:einvoicing:sync --execute --limit=25 --env=prod.
   La commande seule sans --execute est une simulation.
6. Après chaque mois terminé, simuler puis exécuter :
   php bin/console app:einvoicing:bill-usage --month=2026-10 --env=prod
   php bin/console app:einvoicing:bill-usage --month=2026-10 --execute --env=prod
   Exemple octobre : uniquement à partir de novembre, depuis la racine du projet.
   Les tâches planifiées du serveur n’ont pas été inspectées ni installées ici.
7. Vérifier la ligne Stripe puis son inclusion dans la prochaine facture et le
   règlement effectif. Un lot enregistré ne signifie pas un paiement encaissé.

## Garanties vérifiées dans le code et les tests
- Isolation par organisme ; tarif interne de 0,01 EUR HT limité à l’entité 1 et
  son SIRET, sans exemption Stripe. Les anciens accords gratuits ou à 0,02 EUR doivent être renouvelés pour passer au nouveau tarif.
- Consentement et conditions figés avant dépôt fournisseur.
- Compteur unique pour une transmission acceptée avec identifiant fournisseur.
- Pas de consommation pour sandbox, blocage avant envoi ou ancienne tentative dont la gratuité avait déjà été figée. Aucun nouvel accord gratuit n’est accepté.
- Regroupement par client, abonnement et prix ; quantité multipliée par le prix
  accepté et figé (1 centime pour I.T. For You, 3 centimes pour les autres organismes).
- Ajout à l’abonnement désigné, HT, non remisable ; aucun micro-paiement isolé.
- Vérification distante du client, devise EUR, mode live et traitement TVA avant ajout.
- Clé d’idempotence stable ; résultat incertain rapproché en lecture, sans second POST aveugle.
- Webhook signé ; erreur de synchronisation renvoie 500 pour permettre une reprise Stripe.

## Points à connaître avant ouverture commerciale
- Checkout peut créer un essai de 90 jours. L’activation refuse trialing alors que
  le runner l’accepte : la transmission ne peut actuellement être activée qu’après
  passage à active. Décider explicitement si les émissions payantes sont ouvertes
  pendant l’essai avant de modifier cette règle.
- Un abonnement annuel reporte le supplément à sa prochaine facture annuelle.
- Un abonnement clos avant regroupement nécessite un traitement manuel.
- Les anciennes souscriptions sans Stripe Tax ni taux explicite sont bloquées
  lors du regroupement ; les nouveaux Checkouts activent automatic_tax.
- La passerelle exige livemode=true : les tests locaux utilisent des réponses
  simulées. Une recette Stripe test réelle nécessiterait un environnement de
  recette dédié ; ne pas désactiver ce contrôle en production.
- Le connecteur ne couvre pas toutes les factures : notamment les formations
  exonérées de TVA restent hors du périmètre actuel du générateur.
- La politique gratuite intermédiaire est remplacée depuis le 28 octobre 2026
  par 0,01 EUR HT pour I.T. For You, prélevé via Stripe sans commission.
  Les conditions et consommations historiques déjà figées restent inchangées.

Référence API vérifiée : https://docs.stripe.com/api/invoiceitems/create
