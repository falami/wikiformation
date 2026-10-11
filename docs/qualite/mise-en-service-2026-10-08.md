# Mise en service et utilisation du suivi qualité — 8 octobre 2026

Ce guide concerne les **actions de formation**, l’espace administrateur et les corrections décrites dans [le rapport d’audit](audit-2026-10-08.md). Il distingue le déploiement technique, à réaliser par l’exploitant, et les vérifications métier de l’organisme. Les contrôles réalisés localement ne prouvent pas que le serveur de production a reçu les changements.

## 1. Mettre les corrections en service

1. Prévoir un créneau de déploiement. Sauvegarder ensemble la base, les pièces et archives privées, les fichiers de configuration et la version applicative actuellement utilisée. Vérifier que la restauration est possible. Ne pas utiliser les dossiers réels pour les essais.
2. Installer la version validée de l’application et ses dépendances, y compris les fichiers `public/dist/css/quality.css`, les templates, les contrôleurs de téléchargement protégé et **`public/.htaccess`**. La racine web doit viser `public/`. Vérifier `APP_ENV=prod`, l’absence de debug public et les paramètres propres au serveur ; ne pas copier les secrets de développement.
3. Depuis la racine du projet, avec le PHP compatible de l’hébergement, contrôler les migrations avant exécution :

   ```sh
   php bin/console doctrine:migrations:status --env=prod
   php bin/console doctrine:migrations:list --env=prod
   php bin/console doctrine:migrations:migrate --dry-run --env=prod
   ```

   Comparer la liste aux migrations réellement livrées. Les migrations liées aux nouveautés de cet audit sont :

   | Version | Ajout |
   | --- | --- |
   | `Version20261008180000` | Préparations BPF par organisme et dates d’exercice |
   | `Version20261008193000` | Informations publiques supplémentaires de la formation |
   | `Version20261008193500` | Registre qualité et version des fiches |
   | `Version20261008194000` | Proportion d’enseignement à distance de la session |

   Elles ont été contrôlées sur l’environnement local. **L’état de production reste à vérifier.** Le nouveau parcours qualité et son formulaire d’identité réutilisent des colonnes existantes et n’ajoutent aucune migration. Les autres évolutions de la plateforme peuvent avoir leurs propres migrations : ne pas marquer une version exécutée si sa structure n’existe pas, et ne pas lancer `doctrine:schema:update --force` pour remplacer cette procédure.
4. Après vérification du résultat de la simulation et de la sauvegarde, exécuter les migrations prévues, puis reconstruire le cache :

   ```sh
   php bin/console doctrine:migrations:migrate --no-interaction --env=prod
   php bin/console cache:clear --env=prod
   php bin/console cache:warmup --env=prod
   ```

   Respecter la procédure de redémarrage PHP/OPcache de l’hébergeur. En cas d’échec, conserver les journaux et restaurer la version et les données de manière cohérente ; ne pas supprimer les tables ajoutées contenant déjà de nouvelles saisies.

## 2. Vérifier la protection effective des documents

La sécurité des routes Symfony ne suffit pas si le serveur distribue directement un fichier existant. Tester **sur l’hébergement réel**, avec des fichiers fictifs temporaires et un navigateur privé, sans cookie de connexion.

| Famille | Résultat anonyme attendu |
| --- | --- |
| `/uploads/pieces/…`, `/uploads/session/pieces/…` | Accès statique refusé |
| `/uploads/emargements/…`, `/uploads/proofs/…`, `/uploads/depense/…` | Aucun contenu confidentiel distribué |
| `/uploads/entreprise/docs/…`, `/uploads/pdf/…`, `/uploads/devis/…`, `/uploads/conventions/…` | Passage par le contrôleur protégé ; aucun contenu confidentiel anonyme |
| `/uploads/signatures/organisme/…`, `/uploads/tampons/organisme/…` | Aucun accès anonyme aux signatures ou tampons |

Les règles doivent précéder le raccourci « fichier existant ». Sur Apache, vérifier `mod_rewrite` et que la configuration autorise les règles du `.htaccess`. Sur Nginx, un CDN ou un stockage externe, ce fichier n’est pas interprété : transposer les règles et examiner les anciens caches, ainsi que l’accès direct au serveur d’origine. Vérifier également que les fichiers de script placés sous `uploads` ne sont jamais exécutés. Supprimer les témoins après essai.

Recette connectée : l’administrateur autorisé télécharge une pièce depuis son dossier ; un utilisateur d’un autre organisme ne la télécharge pas ; le stagiaire n’accède qu’à ses pièces ; le commercial conserve uniquement son portefeuille. Générer un PDF fictif avec la signature configurée pour vérifier qu’elle apparaît malgré la fermeture des URL anonymes. Voir [la matrice détaillée des accès](../compliance/security-audit-2026-10-08.md).

## 3. Une fois par organisme

Ouvrir **menu administrateur → Qualité & conformité → Mon organisme**.

1. **Compléter l’identité** : recopier le nom, la forme juridique, le SIRET, le numéro de déclaration d’activité, l’adresse et le représentant depuis les justificatifs. Les valeurs existantes sont préremplies. Une sauvegarde partielle est possible ; un numéro inconnu reste vide. Le contrôle du format du numéro ne vérifie pas son existence auprès de l’administration. La région actuellement utilisée dans les documents doit correspondre au récépissé lorsqu’elle accompagne la déclaration d’activité : vérifier les déménagements.
2. **Contrats et signature** : vérifier les clauses par défaut, le nom, la fonction et le pouvoir du signataire. Enregistrer uniquement une signature autorisée. Les nouvelles pièces réutilisent ces informations ; une modification ne réécrit pas les PDF déjà signés ni les anciennes préparations BPF.
3. **Paramétrer les relances** : choisir les types de rappels, délais, répétitions et limites pour l’organisme. Sur le serveur, le traitement `app:trainer-reminders:run` doit aussi être planifié. La commande sans `--execute` simule les candidats sans envoyer de courriel :

   ```sh
   php bin/console app:trainer-reminders:run --entite=1 --env=prod
   ```

   Remplacer `1` par l’organisme à contrôler. Après recette du transport et des destinataires, l’exploitant configure l’exécution planifiée avec `--execute`. Cette option envoie réellement les courriels autorisés par les paramètres. Contrôler les résultats `unknown` avant toute reprise. Aucun SMS gratuit universel n’est fourni par ce dispositif. Voir [le guide des relances](../operations/trainer-reminders.md).
4. Dans **Documenter mes pratiques**, choisir le type de fiche : veille, accessibilité, compétences, sous-traitance, réclamation ou amélioration. Le type et le responsable sont préparés, sans création en base à l’ouverture. Décrire un fait réel, dater l’action, référencer la preuve conservée, choisir une échéance et vérifier l’efficacité avant clôture. Ne pas saisir de données de santé inutiles. Les échéances ouvertes dépassées apparaissent en premier sur le tableau de suivi.

## 4. Une fois par formation, puis lors d’une modification

Dans **Qualité & conformité → Mes formations**, cliquer sur chaque champ manquant. Le raccourci ouvre la fiche à la bonne section : objectifs, prérequis, public, méthodes, évaluation, durée ou informations publiques.

Renseigner les pratiques réelles, délais et modalités d’accès, contact, accessibilité, reconnaissance de l’action et résultats avec leur période et méthode. Ces champs sont réutilisés dans le catalogue et le programme PDF. Vérifier aussi le prix et les éventuelles habilitations avant publication. Ne pas inventer un taux de réussite ni une certification. Les champs vides d’un éditeur de texte, même s’ils contiennent des espaces invisibles, restent signalés.

Le diagnostic de publication examine les fiches **publiques**. Les formations privées doivent aussi être relues avant leur transmission au client ; elles restent accessibles depuis **Toutes les formations**. La duplication d’une formation reprend ses informations : les adapter au nouveau programme avant publication.

## 5. Pour chaque session et chaque bénéficiaire

| Moment | Actions dans la plateforme | Confirmation attendue |
| --- | --- | --- |
| Avant la session | Session → Modifier : programme, intervenants, lieu, dates/créneaux, proportion à distance ; préparer convention/contrat, convocations et documents utiles | Adéquation aux besoins, prérequis, moyens, informations réellement communiquées et consentements nécessaires |
| Pendant la session | Émargements en ligne ou dépôt dédié des feuilles papier ; inscription → Présences & émargements papier pour préciser chaque demi-journée | Identifier les personnes présentes ou absentes à partir des preuves ; un fichier déposé ne crée pas une présence ni une signature électronique |
| Après la session | Reprendre les dossiers signalés dans Qualité & conformité, vérifier les évaluations, comptes rendus et pièces, puis clôturer l’inscription quand elle est prête | Réalisation et durée effectivement justifiées, résultats évalués, retours analysés et anomalies traitées |

Le contrôle des certificats examine au maximum les **30 dernières inscriptions** confirmées, en cours ou terminées de sessions dont les créneaux sont passés. Il inclut les sessions marquées « Terminée » afin de ne pas masquer des preuves encore manquantes ; il exclut les sessions annulées et brouillons. Le nombre total et le nombre contrôlé sont affichés. Utiliser **Toutes les inscriptions** pour examiner les dossiers plus anciens. Le diagnostic n’examine pas automatiquement toutes les obligations pédagogiques d’un dossier.

Une présence partielle avec une pause globale nécessite des créneaux suffisamment précis pour justifier les heures suivies. Aucune durée n’est créée à partir d’un simple pourcentage d’assiduité. Une absence documentée ne doit jamais être convertie en présence pour obtenir un certificat. Une clôture n’est ni une preuve de réussite ni une certification Qualiopi.

## 6. Préparer puis déposer le BPF

1. **Qualité & conformité → Préparer mon BPF**, ou menu **BPF** : choisir les dates exactes de début et fin d’exercice.
2. Contrôler les correspondances proposées et les données non classées, puis générer la préparation. Les données réutilisables proviennent des enregistrements disponibles dans la plateforme ; les données manquantes ne doivent pas être remplacées arbitrairement par zéro.
3. Rapprocher produits, charges, avoirs, heures, publics et sous-traitance avec la comptabilité et les justificatifs. Faire contrôler les situations particulières et justifier les corrections manuelles.
4. Enregistrer la version et exporter le PDF de préparation. L’historique conserve les nouvelles versions avec leurs données sources ; il ne reconstitue pas les versions qui avaient été écrasées avant sa mise en place.
5. Effectuer le **dépôt dans Mon Activité Formation** et conserver l’accusé dans le dossier de l’organisme. **Aucun dépôt BPF automatique n’est réalisé par WikiFormation**. Le PDF de préparation ne remplace pas la déclaration officielle.

## 7. Recette de démarrage

- Un administrateur de l’organisme ouvre le parcours qualité, corrige un champ et retrouve la valeur dans la fiche concernée et le nouveau document de test.
- Une identité partielle ne crée ni numéro administratif ni signature. Une fiche qualité ouverte puis abandonnée ne crée aucune preuve.
- Un dossier fictif sans présence reste signalé ; après saisie des preuves réelles de test et clôture appropriée, la durée affichée correspond aux créneaux justifiés.
- Une préparation BPF de test conserve les versions précédentes après modification ; un autre organisme ne peut ni lire ni exporter ces versions.
- Les liens protégés et l’exécution planifiée des relances sont vérifiés sur le serveur réel. Les essais d’envoi utilisent un transport de test avant activation des destinataires réels.

L’exploitant conserve la date, la version livrée, le résultat des migrations et la recette. Le responsable de l’organisme conserve les justificatifs et vérifie les pratiques. Ce parcours réduit les doubles saisies ; il ne délivre ni certification Qualiopi ni attestation générale de conformité.

## 8. Mettre en service la tarification des émissions électroniques

Pour le raccordement PA, OAuth, les secrets, les commandes de synchronisation et la recette, suivre [le guide technique du connecteur](../compliance/einvoicing-setup-2026-10-08.md).

Cette option est **distincte de l’abonnement fixe** et du montant facturé par l’organisme à ses clients. Le tarif proposé est **0,02 € HT par facture émise via le connecteur en production**, sans quota. L’organisme doit donner son accord dans **Paramètres → Facturation électronique** avec un compte autorisé à gérer son abonnement. Un abonnement Stripe actif, lié au bon client Stripe, est requis. Une ancienne connexion ou une période d’essai sans abonnement Stripe ne vaut pas accord de facturation.

### Avant activation

- Livrer et contrôler les migrations du raccordement PA, puis `Version20261008202000` (consommations et lots de facturation). Cette dernière conserve des preuves de facturation : sa suppression automatique est interdite.
- Configurer l’accès éditeur à la plateforme agréée, la redirection OAuth, le chiffrement des identifiants et le planificateur décrit dans le guide du connecteur. Vérifier l’identité de chaque organisme chez le prestataire. Les identifiants ne sont jamais saisis dans les documents clients.
- Vérifier les conditions commerciales du prestataire : coût par émission, vérification d’identité/KYB, minimums éventuels, engagements et périmètre de réception. **Un écart entre 0,02 € et un prix unitaire fournisseur ne garantit pas une marge nette** : les coûts fixes, TVA applicable, abonnements, traitement Stripe et incidents doivent être couverts. Ne pas annoncer une réception gratuite universelle chez tous les prestataires ; elle n’est simplement pas refacturée par ce compteur applicatif.
- Configurer le traitement fiscal des prestations WikiFormation dans Stripe. La commande exige Stripe Tax actif sur l’abonnement ou des taux par défaut explicites. L’exonération éventuelle de TVA des actions de formation de l’organisme ne s’applique pas automatiquement à la prestation de logiciel. Contrôler le paramétrage réel avec le responsable comptable.

### Ce qui est décompté

Une consommation est créée quand la PA renvoie un identifiant confirmé pour un envoi effectué par le connecteur, avec conditions tarifaires acceptées **avant la tentative**. Cela constate la réception par la PA, pas la livraison au destinataire ni la validation fiscale. Un rejet métier ultérieur de la PA ne retire pas automatiquement cette consommation. Le prix, l’accord et les identifiants d’abonnement/client sont conservés avec la tentative.

Les tests sandbox, les anciennes factures non envoyées par ce parcours, la réception et les tentatives dont le résultat reste inconnu ne sont pas décomptés. Une tentative incertaine ne devient facturable qu’après rapprochement avec un identifiant confirmé. Un même identifiant de facture distante ne produit qu’une consommation par organisme/connexion. Désactiver ou déconnecter la PA arrête les nouveaux envois ; les consommations déjà confirmées restent dues et traçables.

### Exécution mensuelle

Le mois est le **mois civil de confirmation en Europe/Paris**. L’écran **Abonnement** présente la consommation du mois courant. La commande ne peut regrouper qu’un mois terminé. Commencer par une simulation, sans réseau Stripe ni écriture :

```sh
php bin/console app:einvoicing:bill-usage --month=2026-10 --entite=1 --env=prod
```

Après contrôle du relevé et du paramétrage Stripe par l’exploitant, ajouter `--execute` pour créer les lignes réellement facturables :

```sh
php bin/console app:einvoicing:bill-usage --month=2026-10 --entite=1 --execute --env=prod
```

Le mois `2026-10` ne peut être traité qu’à partir du 1er novembre 2026. Remplacer les identifiants par ceux de l’organisme à traiter. Pour une exécution planifiée, cibler le mois précédent ; l’absence de `--entite` traite les connexions de production de tous les organismes, y compris les consommations conservées après déconnexion. Surveiller le code retour et les lots à rapprocher.

Chaque lot figé produit une **ligne regroupée sur la prochaine facture du même abonnement Stripe**, en EUR et hors taxes. Aucun paiement carte isolé de 0,02 € n’est créé. Pour un abonnement annuel, la prochaine facture peut être l’échéance annuelle ; ce mécanisme ne crée pas de facturation mensuelle supplémentaire. Une confirmation tardive ou des conditions d’abonnement distinctes peuvent produire plusieurs lignes séparées, sans recompter les mêmes consommations. Un lot « enregistré » atteste l’ajout d’une ligne Stripe, pas son paiement.

### Incident et reprise

Si la réponse Stripe est perdue, le lot devient incertain. Une reprise normale ne crée **jamais** une autre ligne pour ce lot. Utiliser le rapprochement en lecture :

```sh
php bin/console app:einvoicing:bill-usage --month=2026-10 --entite=1 --execute --reconcile --env=prod
```

Cette variante peut enregistrer localement la correspondance trouvée, mais ne crée pas de ligne Stripe. Si aucune correspondance certaine n’est trouvée, le lot reste bloqué pour contrôle par l’exploitant. Ne pas effacer le lot, ses identifiants, son prix ou ses consommations pour forcer un nouvel essai. La durée de conservation des clés d’idempotence Stripe n’est pas une protection permanente : le journal local est nécessaire. Les abonnements résiliés, les identifiants contradictoires et les lignes distantes altérées doivent être rapprochés avant toute action comptable manuelle ; aucun transfert automatique vers un autre abonnement n’est effectué.

Les tests de cette livraison utilisent des bases isolées et des réponses simulées ; aucun montant n’a été ajouté à un abonnement réel. Une recette dans les environnements de test des prestataires et un contrôle des factures de l’exploitant restent nécessaires avant activation générale.

Références techniques : [création d’une ligne de facture Stripe](https://docs.stripe.com/api/invoiceitems/create), [lignes supplémentaires d’un abonnement](https://docs.stripe.com/billing/invoices/subscription), [idempotence Stripe](https://docs.stripe.com/api/idempotent_requests). Le tarif applicatif ne préjuge pas des tarifs contractuels des prestataires.
