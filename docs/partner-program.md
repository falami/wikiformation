# Programme apporteurs d’affaires

## Utilisation

Menu SUPER → Apporteurs d’affaires (`/fr/super/partenaires`). Créer le compte partenaire, fixer son pourcentage, puis partager son accès `/fr/partenaire`. Pour un nouveau compte, le partenaire utilise « Mot de passe oublié » avec son adresse enregistrée ; aucun mot de passe n’est partagé et aucun e-mail n’est envoyé à la création.

Chaque partenaire dispose d’un code unique et d’un lien `/fr/recommande/CODE`. Le premier code est conservé dans la session du navigateur et proposé dans le formulaire de création d’organisme, où le client peut le confirmer, le remplacer ou le retirer. Aucun suivi interappareils. Le SUPER_ADMIN peut aussi rattacher manuellement un organisme avant son premier abonnement Stripe. Une organisation ne peut avoir qu’un apporteur ; son propre créateur ne peut la parrainer.

L’espace partenaire présente uniquement ses organismes, leurs formules et statuts, ses commissions et ses versements. Les modifications de pourcentage et de frais sont historisées avec leur auteur et leur date. Le taux en vigueur à la date du paiement s’applique, même si le webhook arrive plus tard. Suspendre un partenariat bloque les nouvelles attributions et les nouveaux versements, sans effacer les créances acquises.

## Calcul et échéance

- Chaque facture Stripe `invoice.paid` d’un abonnement rattaché génère au plus une commission.
- Assiette : lignes des prix d’abonnement reconnus dans le catalogue, HT, après remises ; options exclues. Les prix doivent être en TVA exclusive et le paiement en EUR.
- Commission : pourcentage en centièmes de pourcentage, calcul en centimes avec arrondi au centime le plus proche.
- Frais convenus : montant fixe + pourcentage de la commission, plafonnés à celle-ci. Le barème doit correspondre aux conditions convenues et aux coûts du contrat Stripe. Les coûts Stripe annexes (par exemple frais de compte actif) ne sont pas automatiquement refacturés : ils nécessitent un rapprochement. Aucun tarif Stripe n’est inventé.
- Échéance : paiement + 30 jours, puis prochain passage du traitement. Le délai bancaire Stripe s’ajoute. Une facture annuelle produit une commission sur son montant encaissé ; une facture mensuelle en produit une chaque mois.
- Paiement nul, impayé ou essai : aucune commission positive.
- Remboursements non ventilés : réduction proportionnelle au paiement TTC total, y compris si la facture mélange abonnement et options. Un remboursement après transfert déclenche une reprise de la part correspondante ; un échec bloque les versements suivants de cet apporteur.
- Litige ouvert : gel. Litige perdu : reprise intégrale. Les crédits clients, avoirs et paiements partiels ou sans débit unique sont mis « À rapprocher », sans estimation ni versement automatique.

Le journal distingue transfert vers le compte Connect et virement bancaire confirmé. Une reprise peut réduire le net historique ; elle ne signifie pas qu’un virement bancaire déjà exécuté est annulé.

## Activation en production

1. Déployer le code et appliquer `php bin/console doctrine:migrations:migrate --no-interaction --env=prod`. La migration ajoute quatre tables, sans transformer les données existantes. Ne pas exposer le nouveau code avant la migration.
2. Vérifier `APP_URL` / paramètre `app.url` (URL publique HTTPS), `STRIPE_SECRET_KEY` et `STRIPE_WEBHOOK_SECRET` du compte plateforme qui encaisse les abonnements. Ne pas utiliser les comptes Connect des organismes.
3. Activer Stripe Connect sur ce compte, avec comptes Express France, capacité transfers et virements manuels. Le partenaire complète les vérifications et son compte bancaire via le bouton de son espace. Aucun IBAN n’est enregistré dans WikiFormation.
4. Ajouter `invoice.paid` aux événements envoyés au webhook de facturation existant `/fr/stripe/webhook` (ou son URL existante configurée). Conserver les événements existants. Les événements d’avant l’attribution ne sont pas commissionnés. En cas d’événement manqué, le renvoyer depuis Stripe : l’identifiant de facture est unique.
5. Tester avec les clés Stripe de test et un compte Connect de test : abonnement, remise, renouvellement, remboursement, échéance et échec bancaire. Les tests automatisés locaux utilisent un faux prestataire et n’envoient aucun argent.
6. Renseigner les frais réellement convenus et cocher l’autorisation de versement pour chaque partenaire. `PARTNER_PAYOUTS_ENABLED` est désactivé par défaut ; définir `PARTNER_PAYOUTS_ENABLED=1` dans l’environnement serveur uniquement au moment d’activer les paiements.
7. Planifier une commande serveur, par exemple toutes les heures, avec le bon binaire PHP et le répertoire du projet :

```sh
cd /home/vibe1331/apps/wkformation
php bin/console app:partners:payouts --execute --env=prod --no-interaction
```

Sans `--execute`, la commande affiche les états enregistrés et ne contacte pas Stripe : ce n’est pas une validation des coordonnées bancaires. Surveiller son code de retour et ses journaux. Le traitement continue de rapprocher les paiements déjà virés pour détecter les remboursements tardifs.

## Reprise sur incident

Une tentative est enregistrée avant l’appel Stripe. Les identifiants et métadonnées permettent de retrouver un transfert ou virement après une réponse perdue. Au-delà de 23 heures, aucune nouvelle demande incertaine n’est envoyée ; le traitement cherche uniquement l’opération existante. Ne jamais effacer les identifiants ou dates de tentative pour forcer un nouveau versement.

Les lignes « À rapprocher », « Virement échoué » et « Suspendue » bloquent les nouveaux versements du partenaire. Les incidents externes nécessitent un contrôle des opérations dans Stripe ; il n’existe pas de bouton permettant de les déclarer arbitrairement payés. Une reprise automatique est possible lorsqu’une opération incertaine est retrouvée. Un échec définitif ou un avoir nécessite une correction comptable contrôlée avant reprise.

Les verrous sont locaux (`flock`) dans la configuration actuelle : exécuter le traitement sur un seul serveur. Une architecture multiserveur exige un stockage de verrous partagé. Les contraintes uniques sur facture, transfert, virement et attribution complètent cette protection.

## Références prestataire

- [Transferts séparés des paiements et remboursements](https://docs.stripe.com/connect/separate-charges-and-transfers)
- [Virements manuels Connect](https://docs.stripe.com/connect/manual-payouts)
- [Tarification Connect à vérifier sur le contrat du compte](https://stripe.com/connect/pricing)
