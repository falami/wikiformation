# Attestations des sessions terminées

La clôture d'une session hors sous-traitance crée les attestations manquantes pour les inscriptions confirmées, en cours ou terminées dont les présences justifient une durée strictement positive. Les absents, annulations, présences incomplètes et créneaux futurs ou incohérents sont exclus. La durée est calculée sur les présences documentées et les pauses. Le résultat d'évaluation existant est conservé, jamais déduit du statut de la session.

Les espaces stagiaire et entreprise proposent le PDF protégé par leurs droits habituels. Une session rouverte ou des présences devenues insuffisantes rendent le document indisponible. Aucun e-mail n'est envoyé par cette génération.

Pour les sessions déjà terminées et les émargements complétés après clôture, exécuter puis planifier régulièrement :

```sh
APP_ENV=prod php bin/console app:session-certificates:sync
```

La commande est idempotente : elle conserve les attestations existantes. Les heures affichées dans le PDF sont recalculées à partir des présences courantes. Le registre conserve les heures constatées à la création. Vérifier les présences manquantes si une attestation n'est pas créée. Aucun backfill n'est lancé automatiquement lors du déploiement.
