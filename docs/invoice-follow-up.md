# Suivi des factures

Les paramètres sont internes (`facture.meta.follow_up`) : aucune modification des PDF émis, aucune migration nécessaire. Les deux fréquences sont désactivées par défaut et indépendantes : 1, 3, 6 ou 12 mois.

- Renouvellements : date d’émission + période, avec jour plafonné à la fin du mois. Le tableau de bord affiche les échéances atteintes. « Préparer une facture » reprend le client ; les prestations et tarifs restent à confirmer. Après facturation, « Marquer comme traité » avance d’une période. Les occurrences anciennes restent à traiter ; un double clic ne traite pas deux périodes.
- Impayés : solde après paiements et avoirs, hors factures annulées. Première relance à émission + période et au plus tôt le lendemain de l’échéance (émission +30 jours si absente). Les suivantes sont espacées depuis le dernier envoi. Destinataire : adresse de facturation de l’entreprise, sinon destinataire individuel. Les adresses manquantes sont signalées par la commande.
- La fréquence propre à la facture prend priorité sur la relance du workflow formation. Les réservations sont faites sous verrou ; les résultats incertains ne sont jamais réessayés automatiquement. Consulter l’historique des e-mails avant intervention sur un état `processing`/`unknown`.

## Installation du traitement quotidien

Vérifier d’abord la simulation sur le serveur :

```sh
APP_ENV=prod php bin/console app:invoice-reminders:run --entite=1
```

Planifier ensuite dans le cron du serveur (adapter chemins PHP et application) :

```cron
15 8 * * * cd /chemin/wikiformation && APP_ENV=prod /chemin/php bin/console app:invoice-reminders:run --execute >> var/log/invoice-reminders.log 2>&1
```

La commande n’envoie rien sans `--execute`. Aucun cron n’est installé par le code. Le rappel interne de renouvellement est calculé à l’ouverture du tableau de bord et ne dépend pas du cron. Surveiller les sorties non nulles et les adresses absentes. Ne jamais lancer les envois réels sur une copie de production en développement.

## Relances depuis le tableau de bord

Les cases permettent de sélectionner jusqu’à 50 factures, puis « Relancer la sélection » transmet un e-mail par facture. Cette action ponctuelle fonctionne indépendamment de la fréquence automatique et ne l’active pas. Un délai de 24 heures est partagé avec les envois programmés et les workflows. Les factures soldées, annulées, sans adresse ou dont un envoi reste incertain sont ignorées après vérification sous verrou. Le tableau affiche la dernière transmission. Les e-mails HTML utilisent les couleurs de l’organisme et comportent également une version texte.
