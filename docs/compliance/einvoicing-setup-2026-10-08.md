# Raccordement électronique et tarification — 8 octobre 2026

> Mise à jour tarifaire du 28 octobre 2026 : tarif standard 0,03 € HT
> (transmission 0,01 + service 0,01 + provision de traitement 0,01), sans surcharge
> liée au moyen de paiement. Tarif propriétaire 0,01 € HT inchangé ; frais de
> paiement absorbés par l’exploitant. Les anciens tarifs figés sont conservés.


## Ce qui est livré et ce qui reste à activer

Le code relie chaque organisme à **SUPER PDP** par OAuth avec PKCE et contrôle la correspondance du SIREN. Les secrets restent chiffrés côté serveur. Chaque émission conserve son document structuré et son empreinte, la référence distante, les événements reçus et les conditions tarifaires acceptées. La réception reste synchronisable lorsque l’émission automatique est arrêtée. Aucune connexion commerciale, facture réelle, ligne Stripe ni transmission fiscale n’a été créée pendant le développement.

Le connecteur d’émission couvre les nouvelles factures en euros, entre entreprises françaises, avec TVA positive et données d’émission vérifiables. L’annuaire doit fournir une adresse destinataire active non ambiguë. Les factures passent le convertisseur et le validateur du fournisseur avant dépôt. La réponse au dépôt signifie **prise en charge**, pas livraison, validation fiscale ni paiement.

Les avoirs (actuellement globaux TTC), débours, exonérations, catégories mixtes, particuliers, international, secteur public et déclarations d’encaissement ne sont pas automatiquement transmis. Les traiter dans la PA après validation des données métier. Le BPF reste à déposer dans Mon Activité Formation. Ce raccordement n’est ni une certification générale de conformité, ni un remplacement complet de la comptabilité. Les factures historiques sans instantané ne sont jamais reconstruites ni transmises automatiquement.

## 1. Une fois pour WikiFormation

1. Confirmer l’offre éditeur API et son contrat avec SUPER PDP, créer l’application OAuth et demander un compte sandbox. La liste officielle de la DGFiP et les conditions commerciales doivent être revérifiées à la signature.
2. Enregistrer chez le fournisseur une URL de retour exacte, par exemple `https://wikiformation.fr/fr/administrateur/facturation-electronique/retour`. L’environnement local peut utiliser HTTP uniquement sur localhost. Éviter tout proxy redirigeant ce retour vers un autre hôte.
3. Dans le gestionnaire de secrets du serveur (ou `.env.local` non versionné), renseigner :

   ```dotenv
   EINVOICING_CLIENT_ID=identifiant_application
   EINVOICING_CLIENT_SECRET=secret_application
   EINVOICING_REDIRECT_URI=https://wikiformation.fr/fr/administrateur/facturation-electronique/retour
   EINVOICING_ENCRYPTION_KEY=cle_aleatoire_de_32_octets_encodee_en_base64
   ```

   Générer la clé avec un outil cryptographique, par exemple `openssl rand -base64 32`, et la conserver dans le coffre de secrets. PHP doit disposer de Sodium. La sauvegarder séparément des données ; sa perte rend les connexions inutilisables. Une rotation demande un rechiffrement contrôlé ou une reconnexion de chaque organisme. Ne jamais partager les clés dans le chat, les logs ou une capture d’écran.
4. Déployer le code, puis les migrations **20261008200000**, **20261008201000**, **20261008202000**, **20261008203000** dans cet ordre avec la procédure décrite dans [le guide de mise en service qualité](../qualite/mise-en-service-2026-10-08.md). Elles ajoutent connexions, transmissions, préférences de facture et preuves de consommation ; aucune connexion ni activation automatique n’est créée. Les migrations précédentes restent nécessaires selon l’état réel du serveur.
5. Reconstruire le cache de production et vérifier l’absence de profiler/debug. Les journaux du reverse proxy et du serveur ne doivent pas enregistrer la chaîne de requête du retour OAuth (elle contient un code temporaire). Le transport HTTP du connecteur est dédié, sans profiler ni reprise automatique des POST.
6. Faire la recette de l’application OAuth avec le compte fictif : accord/refus/retour expiré, bon organisme, mauvais organisme rejeté, révocation et reconnexion. L’écran sandbox permet de vérifier la connexion ; le traitement planifié exclut volontairement les envois de données réelles en sandbox. La recette d’émission complète chez le fournisseur utilise son outillage et ses sociétés fictives avant ouverture commerciale. Les tests livrés du connecteur simulent le réseau ; ils ne remplacent pas cette recette.

## 2. Une fois pour chaque organisme

1. **Qualité & conformité → Mon organisme** : contrôler identité légale, SIRET, adresse, représentant et justificatifs. Un SIRET correctement formaté n’est pas une vérification administrative.
2. **Préférences → Mentions de facturation** : confirmer délai de paiement, escompte, pénalités, indemnité de recouvrement applicable, régime TVA et exigibilité. Les nouvelles factures reprennent ces valeurs. La date de prestation se renseigne dans la facture ou se prépare dans le devis ; elle n’est pas inventée.
3. **Préférences → Facturation électronique → Production → Connecter mon organisme** : le responsable habilité autorise l’accès. La PA effectue la vérification d’identité/KYB et les formalités de mandat/adressage nécessaires. WikiFormation refuse un compte d’un autre SIREN ou un compte sandbox à cet endroit.
4. L’administrateur habilité à gérer l’abonnement accepte **0,03 € HT par facture transmise**, puis active l’automatisation. L’abonnement doit disposer de ses identifiants Stripe valides. La connexion seule ne vaut pas acceptation du prix. Aucun quota ni supplément implicite n’est ajouté aux anciens abonnements.
5. Créer une première facture complète après activation, suivre son traitement et contrôler son original dans la PA. Une émission est figée : les corrections d’une pièce déjà émise doivent suivre une procédure comptable tracée. Les nouvelles factures incomplètes sont bloquées avant attribution du numéro ; les factures anciennes restent à rapprocher séparément.

## 3. Planification serveur

La commande est en simulation tant que `--execute` n’est pas indiqué :

```sh
php bin/console app:einvoicing:sync --entite=1 --env=prod
```

Elle ne contacte aucun fournisseur, ne déchiffre aucun jeton et n’écrit rien. Remplacer `1` par l’organisme choisi. Après recette, programmer sur le serveur, par exemple toutes les cinq minutes :

```sh
php bin/console app:einvoicing:sync --execute --limit=25 --env=prod
```

Les traitements sont limités par lot et utilisent un verrou de connexion partagé en base. Après une coupure pendant un dépôt, le système rapproche la référence de l’émission distante ; il ne renvoie jamais aveuglément le document. Un état « À rapprocher » persistant nécessite une vérification de l’opérateur auprès de la PA. Les événements reçus sont conservés, sans convertir automatiquement un accusé de réception en règlement comptable.

Surveiller les codes de sortie, erreurs de connexion, files bloquées, consommations et lots Stripe incertains. L’exploitation doit alerter une personne responsable, traiter les expirations/révocations et conserver les justificatifs. Les commandes sont livrées ; aucun cron de production n’a été installé dans cette intervention.

## 4. Refacturation à 0,03 € HT

Le prix est explicite dans les offres, l’espace abonnement et le consentement de l’organisme. La PA facture WikiFormation ; **WikiFormation facture les OF**. Le prix de 0,03 € HT ne supprime donc pas la dette envers le fournisseur. Il crée un revenu en face des émissions du connecteur. La répartition commerciale réserve 0,01 € HT à la transmission, 0,01 € HT au service WikiFormation et 0,01 € HT au traitement du paiement. Cette dernière somme est une provision, pas une mesure des frais Stripe réellement appliqués. Les minimums, KYB, autres flux, hébergement et taxes restent à vérifier. Les coûts des factures reçues, de l’e-reporting et des opérations réalisées hors du connecteur ne sont pas automatiquement récupérés par ce compteur d’émissions. Vérifier ces coûts dans l’offre commerciale avant lancement.

Chaque émission acceptée par la PA avec un identifiant est comptée une fois, même si un contrôle métier ultérieur la rejette. Une tentative incertaine attend le rapprochement avant décompte ; un blocage avant dépôt, un test ou une ancienne facture ne génère pas de consommation. Les conditions tarifaires et l’abonnement sont figés avant l’appel ; aucune tarification rétroactive d’une tentative n’est inventée.

Le compteur affiche la quantité et le total HT du mois civil (Europe/Paris). Les émissions sont regroupées en une ligne sur la prochaine facture de l’abonnement Stripe concerné, sans paiement isolé de 0,03 €. Pour 250 émissions au tarif courant, la ligne est de 7,50 € HT. Les abonnements annuels conservent leur échéance : l’ajout attend la prochaine facture de cet abonnement.

Exemple pour un mois terminé, d’abord en simulation :

```sh
php bin/console app:einvoicing:bill-usage --month=2026-10 --env=prod
```

Après recette Stripe et validation du traitement TVA de l’abonnement :

```sh
php bin/console app:einvoicing:bill-usage --month=2026-10 --execute --env=prod
```

Planifier ce traitement au début de chaque mois pour le mois précédent. Repasser les périodes comportant des lots non rapprochés selon les alertes. Un lot Stripe incertain se rapproche en lecture avec `--execute --reconcile` ; aucun second POST n’est exécuté aveuglément, même après expiration de la clé d’idempotence Stripe. « Enregistré dans Stripe » ne signifie pas « payé ». Un abonnement clos avant l’ajout de sa consommation doit être traité manuellement ; aucune nouvelle souscription ni charge hors abonnement n’est créée automatiquement.

Conserver la facturation existante et Indy tant que le périmètre comptable, la migration des données et la recette de tous les flux utiles ne sont pas validés. La connexion PA n’exige pas que WikiFormation devienne elle-même une plateforme agréée.

## Sources de référence

- [DGFiP : liste des plateformes agréées et rôle des PA](https://www.impots.gouv.fr/je-consulte-la-liste-des-plateformes-agreees)
- [SUPER PDP : tarifs éditeur/API](https://www.superpdp.tech/tarifs/)
- [SUPER PDP : OpenAPI](https://api.superpdp.tech/openapi/superpdp.json)
- [SUPER PDP : connexion OAuth](https://www.superpdp.tech/documentation/4/)
- [SUPER PDP : synchronisation](https://www.superpdp.tech/documentation/14/)
- [Stripe : création d’un invoice item](https://docs.stripe.com/api/invoiceitems/create)

La recette locale utilise SQLite pour les scénarios isolés et des transports simulés. La production, les mandats, les contrats fournisseur, les secrets, les accès externes et les paramètres Stripe réels restent à configurer puis à vérifier.


## Tarif propriétaire — mise à jour du 28 octobre 2026

I.T. For You paie **0,01 € HT par émission via Stripe, sans commission WikiFormation** ; le tarif courant des autres organismes est de **0,03 € HT**, sans quota. La règle serveur exige simultanément l’identifiant d’entité 1 et le SIRET 80813612100023 ; le nom d’affichage et les valeurs envoyées par le navigateur ne donnent aucun droit à une remise. Cette identification doit être vérifiée si les données sont importées dans une nouvelle installation.

La remise s’applique aux nouvelles tentatives, y compris lorsqu’un accord à 0,03 € existait déjà. Un ancien consentement à 0,00 € ne permet pas de facturer 0,01 € : l’administrateur doit accepter à nouveau le tarif. Les conditions des tentatives déjà démarrées et les consommations historiques restent figées. Le montant apparaît dans les préférences et l’abonnement, puis dans la ligne Stripe. La connexion PA, un abonnement Stripe valide et un moyen de paiement configuré sont obligatoires pour tous, y compris I.T. For You. Le tarif propriétaire est un prix commercial fixe, pas une synchronisation automatique avec les coûts du fournisseur.
