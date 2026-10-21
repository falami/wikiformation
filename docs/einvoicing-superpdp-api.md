# Connecteur SUPER PDP — contrat et exploitation

Contrat vérifié le 8 octobre 2026 sur l’[OpenAPI officielle](https://api.superpdp.tech/openapi/superpdp.json), version `1.36.0.beta`, la [documentation OAuth](https://www.superpdp.tech/documentation/4/) et la [documentation de synchronisation](https://www.superpdp.tech/documentation/14). Aucun compte distant, facture réelle, consentement ou paiement n’a été créé pendant le développement. Les validations automatisées utilisent SQLite, des réponses HTTP fictives et une passerelle Stripe fictive.

## Déclenchement et limites

```sh
# Lecture locale uniquement : aucun appel réseau ni écriture
php bin/console app:einvoicing:sync --entite=1

# Exploitation, après connexion OAuth, consentement tarifaire et activation par l’organisme
php bin/console app:einvoicing:sync --entite=1 --limit=25 --execute
```

Le déploiement de la migration et la planification serveur de cette deuxième commande restent nécessaires. Aucun planificateur n’est installé automatiquement. Sans `--execute`, les connexions ne sont pas déchiffrées, rafraîchies ou interrogées. La simulation indique les nouvelles factures du prochain lot et leurs blocages locaux ; elle ne valide ni l’annuaire, ni l’habilitation distante, ni le XML chez le fournisseur.

Chaque organisme possède une connexion indépendante et un verrou partagé en base, renouvelé avant les appels. Les lots contiennent de 1 à 100 éléments, 25 par défaut. Chaque parcours paginé est limité à trois pages par exécution et reprend son curseur persistant au prochain passage. Les événements sont consultés par facture, en privilégiant celles dont le dernier contrôle est le plus ancien. Les factures reçues sont synchronisées même lorsque l’émission automatique est désactivée, tant que la connexion de production reste vérifiée.

L’automatisme de production couvre initialement les **nouvelles factures EUR B2B domestiques françaises, à TVA standard**, munies d’un instantané d’émission intègre et créées à partir de la première activation. Il exclut l’historique antérieur. Une suspension suivie d’une réactivation reprend les factures créées après cette première activation. Les opérations exonérées, les débours, les opérations internationales, les particuliers, les avoirs, les acomptes et le e-reporting ne sont pas générés par ce parcours : les données concernées sont bloquées ou hors périmètre, jamais requalifiées automatiquement. Le bac à sable ne reçoit aucune facture réelle du portefeuille, même avec `--execute`.

## Envoi et rapprochement

1. Contrôler la session OAuth vérifiée et l’identité de la société distante : environnement, identifiant distant, SIREN égal à celui de l’organisme local.
2. Vérifier l’instantané de facture et les conditions tarifaires explicites. Lire l’annuaire public du destinataire. Choisir son adresse racine active `0225:SIREN`, sinon son unique adresse active compatible. Plusieurs adresses sans racine provoquent un blocage à résoudre explicitement.
3. Convertir le JSON EN16931 en UBL puis exiger `validation_reports.data[0].is_valid === true`. Ces opérations communiquent le contenu de la facture au prestataire, seulement lors de l’exécution autorisée.
4. Sauvegarder le XML et son SHA-256. Rafraîchir si nécessaire le jeton OAuth et persister immédiatement le nouveau refresh token.
5. Marquer la transmission `submitting`, dater la tentative et **flusher avant** `POST /invoices`.
6. Une réponse valide avec identifiant distant produit `submitted` et une consommation tarifaire unique. Cet acquittement signifie mise en file, pas validation fiscale ni réception par le client.

Un code 4xx explicite produit `rejected`. Une erreur de transport, une réponse 5xx, un délai dépassé ou une réponse ambiguë produit `uncertain`. Au redémarrage, un `submitting` laissé par un processus interrompu devient également `uncertain`. **Aucune de ces tentatives n’est renvoyée automatiquement.** Le champ `external_id` est une référence de rapprochement, pas une clé d’idempotence garantie par le fournisseur.

La liste des émissions distantes est parcourue en ordre croissant pour rapprocher une référence `wf-…` d’une tentative locale incertaine. L’absence de résultat n’autorise aucun renvoi. La plateforme ne propose pas de filtre `external_id` documenté : le rapprochement d’un compte ayant beaucoup d’historique peut nécessiter plusieurs passages. Une résolution manuelle d’une émission incertaine doit examiner la plateforme avant toute nouvelle émission.

Les consommations sont enregistrées uniquement après confirmation/rapprochement d’un identifiant distant, avec le tarif et l’abonnement acceptés avant la tentative. Une émission incertaine non rapprochée, une réception, une émission extérieure au connecteur ou un test ne génère pas de consommation. Le registre et son traitement Stripe sont gérés par `EInvoiceUsageBilling` ; le runner ne contacte pas Stripe.

## API utilisée

Base fixe : `https://api.superpdp.tech`. Même URL pour les deux environnements, distingués par le compte. Les requêtes n’autorisent ni redirection ni reprise automatique, avec des délais bornés. Le transport dédié refuse les clients Symfony collectant les secrets dans le profiler ou réessayant les POST.

| Opération | Contrat |
|---|---|
| Autoriser | `GET /oauth2/authorize`, authorization code, état aléatoire et PKCE S256 |
| Obtenir/renouveler les jetons | `POST /oauth2/token`, formulaire OAuth, client secret dans le corps |
| Vérifier l’habilitation | `GET /v1.beta/oauth2_sessions/me` |
| Vérifier l’organisme | `GET /v1.beta/companies/me` |
| Annuaire destinataire | `GET /v1.beta/french_directory/entries?number=SIREN`, sans jeton |
| Convertir | `POST /v1.beta/invoices/convert?from=en16931&to=ubl`, JSON, sans jeton |
| Valider | `POST /v1.beta/validation_reports`, multipart `file_name`, sans jeton |
| Émettre | `POST /v1.beta/invoices?external_id=…&processing_rule=B2B`, XML brut |
| Émissions/réceptions | `GET /v1.beta/invoices?direction=out|in&order=asc&starting_after_id=…&limit=…` |
| Événements | `GET /v1.beta/invoice_events?invoice_id=…&starting_after_id=…&limit=…` |
| Document original | `GET /v1.beta/invoices/{id}?format=original` |

Les réceptions enregistrent un index local minimal (identifiant, compte distant, sens, date distante et règle de traitement). Le téléchargement authentifié du document original utilise l’API après contrôle des droits de l’organisme ; aucun fichier n’est publié dans un répertoire web. Le connecteur ne transforme pas une réception en dépense comptable et ne constitue pas à lui seul une archive probante indépendante du fournisseur.

## Statuts et obligations restantes

Les événements fournisseur sont conservés avec leur identifiant, date et code, sans les forcer dans une machine à états exclusive : `api:uploaded`, `api:validated`, `api:invalid`, `api:sent`, `api:rejected`, `api:received`, `api:acknowledged`, `api:accepted`, ainsi que les événements réglementaires `fr:*` et les futurs codes. Un `fr:211` ou `fr:212` ne crée jamais de paiement local et ne marque jamais la facture payée.

La recette réelle OAuth/annuaire/transmission/réception, la vérification des statuts réglementaires attendus, la gestion des mandats et l’archivage prévu au contrat du prestataire doivent être validés avant généralisation. Les clés, URL de callback autorisées et compte de production doivent être configurés par l’exploitant. La conformité de chaque opération fiscale et la complétude d’un e-reporting ne sont pas établies par la seule validation d’un XML.

Tarification fournisseur : l’[offre API éditeur](https://www.superpdp.tech/tarifs/) est facturée à l’éditeur et peut être refacturée à ses clients. Les [conditions contractuelles](https://www.superpdp.tech/legal/11/) ne documentent pas un réglage OAuth garantissant un paiement direct par chaque organisme. Les frais fournisseur de réception, minimums, vérification d’identité et autres coûts éventuels ne sont donc pas tous couverts par le seul tarif local de 0,02 € HT par émission. Leur prise en charge doit être arrêtée contractuellement avant activation commerciale.

### Exemption interne I.T. For You
L’entité 1 portant le SIRET 80813612100023 bénéficie d’un tarif interne nul.
L’activation conserve la confirmation explicite, les permissions et la connexion
production vérifiée, mais ne requiert pas d’abonnement Stripe. Le traitement
capture les conditions à zéro avant transmission et ne crée aucune consommation
facturable pour ces nouvelles tentatives. Les anciennes conditions déjà figées
restent inchangées. Les frais directs du fournisseur restent distincts.
Aucune migration n’est nécessaire pour cette politique ; déployer le code et les
templates puis vider le cache de production avant d’activer depuis les préférences.
