# Protection des contacts publics

Les formulaires de la page Contact, du pied de page et des sites d’organismes utilisent Cloudflare Turnstile. Le calcul arithmétique a été supprimé. La protection n'est active en production qu'après configuration des clés et déploiement du code.

## Créer les clés

Dans le tableau de bord Cloudflare, ouvrir Turnstile puis Add widget :

- Nom : WikiFormation – Contact.
- Domaines : wikiformation.fr et test.wikiformation.fr, sans protocole ni chemin. Ajouter également les domaines personnalisés d'organismes qui utilisent ces formulaires.
- Mode : Managed.
- Préautorisation (« Skip future security rule challenges » / pre-clearance) : désactivée.

Le domaine n'a pas besoin d'être transféré chez Cloudflare. Créer le widget et conserver la Site key et la Secret key. La clé secrète ne doit pas apparaître dans Git, dans une capture d'écran ou dans les fichiers JavaScript.

## Configuration du serveur

Avant de déployer cette version, modifier le fichier privé de configuration de chaque environnement, par exemple :

```bash
nano "$HOME/apps/wkformation/.env.local"
```

Ajouter ou remplacer ces deux variables avec les valeurs réelles :

```dotenv
TURNSTILE_SITE_KEY='CLE_PUBLIQUE_DU_WIDGET'
TURNSTILE_SECRET_KEY='CLE_SECRETE_DU_WIDGET'
```

Ne pas copier les exemples littéralement. Ne pas utiliser les clés de démonstration Cloudflare en production : l'application les refuse. Les deux clés doivent appartenir au même widget.

Si l'installation utilise un fichier compilé `.env.local.php`, le régénérer après modification avec `composer dump-env prod` depuis le répertoire de l'application. Exécuter ensuite le déploiement habituel et vérifier le formulaire sur chaque domaine autorisé.

Sans clés valides, le formulaire reste indisponible et aucun message ne peut contourner la vérification. Le message de l'interface propose de contacter l'organisme par e-mail. Ne pas déployer avant d'avoir préparé ces clés si le formulaire doit rester disponible.

## Contrôles

- Le script Cloudflare est chargé à la première interaction avec un formulaire, une seule fois par page.
- Les deux formulaires d'une même page ont des widgets et des jetons indépendants.
- Le serveur appelle Siteverify et contrôle le résultat, le domaine exact, l'action `public_contact` et un identifiant aléatoire lié à la session du navigateur.
- Les jetons sont à durée limitée chez Cloudflare. Un verrou et un cache local empêchent leur réutilisation concurrente sur ce serveur.
- Une panne réseau, une réponse invalide, un mauvais domaine, une autre session ou une configuration manquante bloque l'envoi.
- Le message saisi reste affiché lorsque Siteverify refuse la vérification, avec une nouvelle possibilité de vérifier.
- CSRF, champ piège et limitation de cinq requêtes par adresse IP sur quinze minutes restent actifs. La limitation intervient avant le traitement du formulaire et les appels externes.
- Le nom, l'e-mail et le message ne sont pas envoyés par l'application à Siteverify. Le navigateur se connecte à Cloudflare pour la vérification ; l'information est affichée dans le formulaire et la politique de confidentialité.
- Les journaux de vérification n'enregistrent pas les jetons, les secrets ni les réponses brutes du fournisseur.

Sur une architecture avec plusieurs serveurs, partager le cache des jetons, le stockage des limites et les verrous. Vérifier la configuration des proxys de confiance pour que la limite IP utilise l'adresse effective du visiteur.

Si une politique CSP est appliquée par l'hébergeur, autoriser `https://challenges.cloudflare.com` dans `script-src` et `frame-src`, selon la documentation officielle. Ne pas héberger une copie locale du script Turnstile.

## Validation avant mise en ligne

Les tests PHP utilisent un fournisseur simulé : ils ne constituent pas une validation des clés réelles ni des domaines configurés chez Cloudflare. Tester sur le site de préproduction un envoi autorisé, un formulaire laissé ouvert plus de cinq minutes, les deux formulaires d'une page et un navigateur avec le script bloqué. Utiliser une adresse de test pour ne pas générer de messages indésirables.

Aucune protection ne garantit zéro spam, notamment face à une personne qui remplit réellement un formulaire. Surveiller les refus et les statistiques Turnstile après activation avant d'ajuster les limites.

Références :
- https://developers.cloudflare.com/turnstile/get-started/
- https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/
