# Rappel des sessions la veille

Appliquer la migration `Version20261025000500` avant de déployer les formulaires. Le switch est désactivé par défaut ; son activation est propre à chaque session.

Installer sur le serveur un traitement toutes les 15 minutes, avec le même PHP et le même environnement que l'application :

```cron
*/15 * * * * cd /CHEMIN/WIKIFORMATION && APP_ENV=prod /CHEMIN/PHP bin/console app:session-start-reminders:run --execute >> var/log/session-start-reminders.log 2>&1
```

Sans `--execute`, la commande simule et n'envoie rien. Elle ne crée aucun registre en simulation.

Les sessions publiées ou complètes sont éligibles, uniquement la veille du premier créneau, à partir de 9 h dans le fuseau du site (Europe/Paris par défaut). Seuls les stagiaires confirmés ou en cours avec une adresse valide sont inclus. Aucune relance tardive après le jour prévu. Le message contient le planning, le lieu et l'organisme, en HTML et en texte.

Le registre `session_start_reminder_delivery` réserve chaque couple session/stagiaire/date de début avant l'envoi. `sent` indique une remise au transport, pas une lecture ou une livraison finale. `pending` après interruption ou `uncertain` indique un résultat à contrôler dans l'historique des e-mails et chez le transporteur ; aucune nouvelle tentative automatique n'est faite pour éviter un doublon. Ne retirer une réservation qu'après avoir vérifié que le message n'a pas été envoyé. Le code de sortie signale les erreurs de transport du passage courant. Surveiller aussi les réservations `pending` et `uncertain`.

Le verrou flock empêche deux commandes sur le même hôte ; la clé primaire du registre protège également contre un double envoi multi-hôte. Un changement de date de début ouvre un nouveau rappel la veille de la nouvelle date. Désactiver le switch empêche les futurs envois mais ne rappelle pas les e-mails déjà envoyés.
