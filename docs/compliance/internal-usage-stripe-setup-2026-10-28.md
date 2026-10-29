# Raccordement Stripe interne I.T. For You

La route `app_billing_internal_usage` est réservée à l'entité 1 portant le SIRET `80813612100023`, avec authentification complète et permission `BILLING_MANAGE`. Elle reste accessible lorsque les droits applicatifs de l'abonnement local sont expirés, sans ouvrir ces droits à d'autres pages. Le bouton est proposé dans la facturation et les préférences de facturation électronique.

Le formulaire présente une base récurrente de 0 € par mois et le tarif interne de 0,01 € HT par émission électronique confirmée. Il exige le jeton CSRF, l'acceptation explicite de ce tarif et la version/empreinte actuelles des CGV. Le texte contractuel, son empreinte, le tarif et l'auteur sont conservés avant toute requête d'écriture Stripe. Aucun abonnement public payant n'est choisi implicitement.

Le service conserve le même abonnement local, son plan, ses limites, ses options et son historique d'essai. Il demande à Stripe une unique ligne mensuelle EUR à zéro, hors taxes, avec Stripe Tax et collecte obligatoire d'une carte ou d'un prélèvement SEPA. Cette création nécessite la configuration effective des produits Stripe correspondants sur le compte de production. Le contrôle des émissions continue de vérifier le moyen de paiement distant et, pour SEPA, le mandat actif : l'ouverture de Checkout n'autorise aucune émission.

Une réservation unique par organisme (`billing_internal_usage_setup`, migration `Version20261028120000`) fige les requêtes client/Checkout, les références et les clés d'idempotence. L'existence d'un abonnement Stripe non terminé empêche une nouvelle souscription. Un solde client non nul, une ligne de facturation en attente ou une facture ouverte/brouillon empêchent également la configuration, pour ne pas présenter à zéro un rattachement qui pourrait collecter une dette antérieure. Un résultat de création perdu est rejoué avec les mêmes paramètres et la même clé, pendant la durée de validité de la session. Une session connue est relue et réutilisée seulement si elle est toujours ouverte et liée au bon client. Une session terminée attend sa confirmation ; une tentative expirée ou incertaine demande un rapprochement explicite. Aucun nouvel identifiant d'opération n'est créé pour contourner cette attente. Les appels cURL sont bornés à 10 secondes (connexion 3 secondes), puis les réglages partagés sont restaurés.

Les événements signés `checkout.session.completed` et `customer.subscription.created/updated` passent par une validation de la réservation locale avant rattachement. Le client, l'entité, l'abonnement local et les métadonnées doivent correspondre ; le prix doit rester à zéro EUR, mensuel, hors taxes, en quantité unique. Un événement de souscription reçu avant la confirmation Checkout ne contourne pas cette vérification. Les événements des abonnements ordinaires sans réservation ni marqueur interne suivent leur traitement habituel. Les modifications distantes incohérentes sont refusées et doivent être rapprochées, elles ne changent pas silencieusement le plan local.

Les consommations confirmées utilisent ensuite le regroupement mensuel existant. Le prix d'une émission ne constitue pas un prélèvement individuel d'un centime. Stripe peut reporter un montant de facture inférieur à son minimum de paiement sur le solde de facturation client ; une facture marquée payée par ce mécanisme n'est pas une preuve d'encaissement bancaire. Les frais du compte Stripe de l'exploitant restent supportés par celui-ci, indépendamment du prix interne de service.

## Mise en service et limites

La migration doit être appliquée avant d'activer le nouveau code, y compris le webhook Stripe : la vérification d'une réservation locale intervient aussi pour les événements d'abonnements ordinaires. Déployer le webhook sans sa table provoquerait des échecs de synchronisation. Elle a été préparée et vérifiée syntaxiquement, mais n'a pas été exécutée sur la base réelle pendant cette intervention. Aucun client, abonnement, mandat, paiement ou invoice item Stripe réel n'a été créé. Les tests utilisent SQLite et un transport Stripe simulé.

La première configuration suppose qu'un abonnement local et son plan existent déjà. Le service ne devine ni un plan ni des quotas en cas d'absence. Un abonnement Stripe déjà rattaché est géré via le portail existant. Le rétablissement d'une configuration expirée, supprimée ou incohérente exige un rapprochement administratif ; aucun bouton de recréation automatique n'est fourni, afin d'éviter les doublons financiers. Ne pas supprimer la réservation ou vider ses références pour tenter de reprendre : vérifier d'abord dans Stripe les identifiants et l'état de toutes les opérations associées.

Sources Stripe : [création Checkout](https://docs.stripe.com/api/checkout/sessions/create), [solde de facturation et montant minimum](https://docs.stripe.com/billing/customer/balance).


## Correctif du 29 octobre 2026 : expiration avant création

Le code initial figeait `expires_at` à l’heure de réservation + 1 800 secondes, avant les appels de contrôle du client, des factures et des abonnements. Stripe exige un délai de 30 minutes minimum **au moment de la création de Checkout**. Le temps des contrôles rendait donc la requête invalide. L’erreur était remplacée par un message d’indisponibilité puis, après expiration, par une demande de rapprochement.

Les nouvelles réservations omettent `expires_at` et bénéficient des 24 heures par défaut de Stripe. Les paramètres restent figés pour les reprises avec la même clé. Sans identifiant de session connu, la limite locale de 23 heures depuis la première tentative protège toujours contre la réutilisation d’une clé que Stripe pourrait avoir purgée.

Pour l’ancienne erreur uniquement, un nouveau clic avec acceptation des conditions peut reprendre automatiquement si :

- l’ancien `expires_at` est dépassé depuis plus de cinq minutes et un client Stripe est déjà enregistré ;
- aucune session ni souscription Stripe n’est rattachée localement ;
- le client correspond à l’organisme, son solde est nul, aucune facture ouverte/brouillon ni ligne en attente n’existe, et aucune souscription distante n’est active ;
- la liste des Checkout du client est complète (au maximum 100 pour cette reprise), cohérente, et ne contient aucune session de souscription ouverte ou terminée.

Sous verrou de l’organisme, la réservation et l’abonnement sont rechargés. Toute évolution concurrente bloque cette reprise et invite à recharger. L’ancien corps de requête, sa clé et sa date restent dans `request.checkout_history`. Une nouvelle clé est figée, l’expiration absolue est retirée, et les acceptations ancienne/nouvelle sont conservées par l’audit `subscription.internal_usage_resumed`. L’identifiant de réservation et le client sont conservés. Aucun plan, droit ni abonnement distant n’est modifié par le rapprochement.

Les erreurs API sont désormais journalisées avec opération, classe, statut HTTP, code, paramètre et référence Stripe, sans corps de requête, secret ni message brut. Les erreurs de validation sont présentées comme telles. Les exceptions de programmation ne sont plus transformées en faux incidents réseau.

### Diagnostic sur le serveur

Après déploiement, depuis la racine du projet :

```sh
php bin/console app:billing:internal-usage:diagnose 1 --env=prod
```

Cette commande est en lecture seule : elle inspecte la réservation locale et les sessions/abonnements Stripe sans créer ni supprimer d’objet. Ajouter `--local-only` pour rester hors réseau. Si une session terminée ou un abonnement existant est trouvé, vérifier les livraisons du webhook avant toute reprise. Ne pas supprimer la réservation ni vider ses références.

La reproduction et les tests du correctif utilisent SQLite et des réponses Stripe simulées. Aucun diagnostic de la base ni du compte Stripe de production n’a été effectué depuis l’environnement local ; le défaut de temporisation est établi par le code et la documentation, pas par un journal Stripe de la tentative réelle.
