# Relances formateurs

Paramètres par organisme : `/fr/administrateur/{entite}/preferences/formateurs/relances` (permission ENTITE_PREFERENCE_MANAGE). Les quatre notifications sont désactivées par défaut. Les délais sont en jours, envoi dès la prochaine exécution après échéance. Pas de SMS ni de prestataire facturé ajouté.

## Mise en service

1. Déployer les fichiers et appliquer `php bin/console doctrine:migrations:migrate --no-interaction` (migration Version20261008133000).
2. Vérifier MAILER_DSN et l’URL absolue du routeur (`framework.router.default_uri`).
3. Simuler : `php bin/console app:trainer-reminders:run --entite=1` (aucun envoi ni journal de livraison écrit).
4. Sur Docker Compose : `docker compose up -d --build trainer-reminders`. Le service passe toutes les minutes. Sur un autre hébergement, exécuter toutes les cinq minutes :

```cron
*/5 * * * * cd /chemin/wikiformation && APP_ENV=prod php bin/console app:trainer-reminders:run --execute --no-interaction >> var/log/trainer-reminders.log 2>&1
```

5. L’administrateur active les catégories souhaitées. Les contrats déjà au statut ENVOYE et les sessions passées incomplètes deviennent éligibles. Aucun paramètre n’est activé automatiquement lors de la migration.

## Règles

- Contrats : cliquer « Prêt à signer » sur la fiche, ou choisir ENVOYE. BROUILLON, SIGNE, ARCHIVE et RESILIE n’entraînent aucun envoi. Notification initiale unique par version/formateur, délai de rappel à partir de sa remise à la messagerie. Les rappels nécessitent l’activation de la notification initiale.
- Après formation : délai après la fin de la dernière journée, répétition à partir du dernier envoi, maximum configurable par session/formateur/sujet. Aucun rattrapage en rafale.
- Arrêt si terminé, annulé, formateur retiré, compte inactif ou catégorie désactivée. FULL correspond aux places occupées, pas à la complétude administrative.
- Émargements : règles du tableau de bord, QR invités, signatures et présences papier/absences manuelles, suivi clôturé et sous-traitance pris en compte.
- Appréciations : questionnaires soumis ou compte rendu/appréciation papier validé par l’organisme. Ces rappels vont au formateur, jamais directement aux stagiaires.
- Une clé unique en base empêche deux processus d’envoyer le même rappel. La réservation est validée avant l’appel au transport. L’historique est visible dans les paramètres (dates Europe/Paris).

## Incident d’envoi

`unknown` ou `processing` bloquent ce sujet pour éviter les doubles envois après interruption ou réponse SMTP ambiguë. « Envoyé » signifie remis à la messagerie, pas preuve de lecture ni de réception. Vérifier les logs et la messagerie avant de corriger le journal : si le message est confirmé envoyé, renseigner `status=sent` et `sent_at` UTC ; s’il est confirmé non envoyé, supprimer uniquement sa réservation pour permettre une nouvelle tentative. Ne jamais purger sans vérification. La commande retourne un code non nul pour les erreurs du passage courant.
