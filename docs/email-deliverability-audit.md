# Audit des emails — 21 octobre 2026

## Corrections appliquées

- Politique commune sur MessageEvent, après rendu Twig : expéditeur et enveloppe centralisés via MAIL_DELIVERY_FROM (défaut contact@wikiformation.fr). Le nom de l’organisme est conservé ; son adresse reste en Reply-To. Tous les appels à MailerInterface sont concernés, y compris factures, contact et authentification.
- Alternative texte ajoutée aux Email HTML qui n’en ont pas ; liens conservés. Les textes métier explicites et pièces jointes sont conservés.
- Route /_mail-test limitée à dev, POST, SUPER_ADMIN et jeton CSRF mail_test ; suppression de l’exposition brute des erreurs SMTP.
- Commande app:email:check sans envoi ni affichage du DSN. Un code non nul signifie qu’une vérification est nécessaire ; ce n’est pas une preuve de panne SMTP.

## DNS publics observés

- SPF : un enregistrement autorisant 109.234.164.16, a, mx et include:spf.jabatus.fr, avec ~all.
- DKIM : clé RSA publiée au sélecteur default. Publication ne prouve pas que les messages sont signés avec cette clé.
- DMARC : v=DMARC1; p=none; — observation, sans destination rua.
- MX : mail.wikiformation.fr, adresse 109.234.164.16.
- PTR de cette adresse : 109-234-164-16.reverse.odns.fr.
- L’IP sortante effective, son HELO, la validité TLS et la réputation ne sont pas prouvés par ces consultations. Le relais SPF peut correspondre à d’autres IP sortantes.

## Constats de fiabilité et limites

Les appels d’envoi sont dispersés entre MailerManager, les contrôleurs (facturation, contact, réinitialisation), les services de vérification et CommercialMail. La politique événementielle couvre ces chemins sans devoir les modifier individuellement.

Les workflows et relances de factures réservent leurs envois avant exécution et isolent les résultats incertains. Les relances formateurs ont un historique et des limites. Ces mécanismes doivent être conservés : une erreur réseau peut survenir après acceptation SMTP et une reprise aveugle peut doubler un email.

Il n’existe pas de file d’envoi globale configurée avec transport d’échec Messenger. Une file ne doit pas être activée en une ligne : les journaux existants marqueraient SENT dès la mise en file, certains jetons expireraient en attente et des pièces jointes pourraient devenir indisponibles. Une évolution dédiée doit rendre les messages autonomes, distinguer QUEUED / ACCEPTED / FAILED / UNKNOWN, superviser un worker et préserver l’idempotence métier.

SENT signifie actuellement remise au transport, pas réception en boîte principale. Aucun traitement global des rebonds définitifs ou plaintes du fournisseur n’a été trouvé. Il faut un webhook authentifié du prestataire, une liste d’exclusion et une gestion des erreurs temporaires/définitives. Ne jamais fabriquer un statut DELIVERED depuis le seul succès SMTP.

Les emails de prospection n’ont pas de mécanisme global de désinscription détecté. Les séparer du flux transactionnel et prévoir une désinscription réelle et persistante avant des campagnes. Ne pas ajouter un faux en-tête List-Unsubscribe sans endpoint fonctionnel.

Le fichier .env versionné contient un DSN avec une valeur ressemblant à un mot de passe. Si cette valeur a déjà été utilisée, la renouveler côté fournisseur et conserver les secrets uniquement dans la configuration privée de production. Ne pas copier le DSN dans les rapports ou tickets.

## Mise en production

1. Vérifier que contact@wikiformation.fr est une adresse autorisée par le SMTP, avec réception/suivi des erreurs. Sinon configurer MAIL_DELIVERY_FROM avec une adresse authentifiée du même domaine dans .env.local (ou variables serveur).
2. Utiliser le nom SMTP officiel correspondant au certificat TLS. Pour le port 465 utiliser smtps:// ; pour STARTTLS, smtp:// avec require_tls=true si pris en charge. Encoder les caractères spéciaux des identifiants. Ne jamais désactiver verify_peer.
3. Depuis le dossier de l’application :

```sh
# Si .env.local.php est utilisé, régénérer d’abord les variables compilées.
composer dump-env prod
php bin/console cache:clear --env=prod
php bin/console app:email:check --env=prod --dkim-selector=default
```

4. Dans l’hébergement, vérifier la signature DKIM active et le domaine de signature. Envoyer ensuite, volontairement, un email fonctionnel vers des boîtes de contrôle Gmail/Outlook/Yahoo et examiner Authentication-Results : spf=pass, dkim=pass, dmarc=pass avec alignement sur le From. Aucun email réel n’a été envoyé pendant cet audit.
5. Configurer une destination de rapports DMARC réellement surveillée. Après inventaire de tous les expéditeurs légitimes et observation des rapports, passer progressivement à quarantine puis reject. Ne pas changer cette politique à l’aveugle.
6. Contrôler quotas et réputation du relais avec l’hébergeur. Si un prestataire transactionnel est adopté, configurer ses DNS et webhooks avant bascule ; conserver les réponses dirigées vers les organismes.

## Sources

- https://support.google.com/mail/answer/81126
- https://senders.yahooinc.com/best-practices/

Aucun accès aux journaux SMTP de production ni en-têtes d’un message reçu : le diagnostic de délivrabilité réelle reste à confirmer. Aucune promesse de placement garanti en boîte principale.
