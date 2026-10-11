# Contrôle des accès aux justificatifs et des signatures — 8 octobre 2026

Périmètre : contrôle technique ciblé des pièces d'inscription, pièces de session, archives PDF, justificatifs, images de signature et signatures de conventions. Ce document ne constitue ni un audit RGPD exhaustif, ni une certification Qualiopi, ni une qualification de signature électronique. Aucune donnée réelle ni infrastructure de production n'a été modifiée.

## Références vérifiées

- [CNIL — Gérer les habilitations](https://www.cnil.fr/fr/securite-gerer-les-habilitations) : accès limités aux besoins, suppression des droits devenus inutiles.
- [CNIL — Gérer les utilisateurs](https://www.cnil.fr/fr/gerer-les-utilisateurs) : authentification avant accès aux données personnelles.
- [CNIL — Tracer les opérations](https://www.cnil.fr/fr/securite-tracer-les-operations) : traces utiles à la détection et à l'analyse d'incidents.

## Défauts corrigés

1. `DossierInscriptionController` contrôlait le rôle dans l'organisme demandé mais pas l'appartenance du dossier ou de la pièce à cet organisme. Un identifiant d'un autre organisme pouvait atteindre les opérations de lecture, modification, validation et suppression. Les contrôles portent maintenant sur toute la chaîne pièce → dossier → inscription → session → organisme. Les associations contradictoires sont refusées.
2. La simple ouverture du formulaire créait un dossier en base. La création est différée à une soumission valide.
3. L'upload AJAX de pièces stagiaires ne contrôlait ni le type réel du fichier ni sa taille. Il accepte maintenant uniquement PDF/JPEG/PNG/WEBP/GIF, jusqu'à 10 Mo, avec extension déterminée côté serveur et nom aléatoire.
4. La suppression d'une pièce validée était interdite par l'action dédiée, mais possible en l'omettant dans la collection du formulaire stagiaire. La suppression, le remplacement et le changement de type d'une pièce validée sont maintenant refusés par ce formulaire aussi.
5. Les pièces créées depuis le formulaire stagiaire n'avaient pas systématiquement l'inscription, le créateur et l'organisme rattachés. Ces liens sont désormais établis avant persistance.
6. Les téléchargements de pièces concernés reçoivent `Cache-Control: private, no-store` et `X-Content-Type-Options: nosniff`. Les chemins de fichiers ne peuvent pas contenir de traversée de répertoires.
7. Sous Apache, la règle `public/.htaccess` interdit l'accès statique direct à `uploads/pieces` et `uploads/session/pieces`, avant le raccourci des fichiers existants. Elle bloque aussi les extensions de scripts exécutables dans les uploads.
8. Les trois chemins de signature des conventions stagiaire/entreprise effaçaient le chemin PDF immédiatement après signature. Le document signé devenait donc introuvable. Ils utilisent maintenant un service commun conservant l'aperçu consulté en stockage privé et enregistrant l'auteur, la date, la méthode et l'empreinte SHA-256 dans une `ConventionRevision`. Une signature complémentaire conserve les octets du document précédent. Le PDF original n'est pas recalculé après consentement : les métadonnées et le dessin restent dans les données de signature et la trace associée.
9. La signature exige désormais que le compte ait ouvert l'aperçu et que son empreinte de contenu soit inchangée. Un verrou sur la convention protège les confirmations concurrentes. Un prétendu PNG doit réellement être une image PNG de dimensions et taille bornées.
10. Les liens de convention et de devis du dashboard entreprise utilisaient des chemins publics mal construits. Ils empruntent maintenant les routes autorisées des documents.
11. Les URL historiques des émargements, PDF archivés, justificatifs de paiement/dépense, documents entreprise et images de signature/tampon d'organisme sont désormais interceptées avant le service statique Apache. `ProtectedUploadController` exige une authentification complète, retrouve l'enregistrement exact, contrôle l'organisme, les associations et le rôle/portefeuille. Les fichiers sans enregistrement ou liés à plusieurs archives sont refusés. Les membres suspendus n'accèdent plus aux fichiers ; un commercial conserve uniquement les documents de son portefeuille attribué. Les chemins réels restent confinés au répertoire autorisé, y compris en présence de liens symboliques. Aucun document historique n'est déplacé ou supprimé.
12. Les modèles PDF concernés embarquent les signatures et tampons de l'organisme depuis le fichier local, via `private_document_image`. Ils ne dépendent plus d'un téléchargement anonyme de ces images. Le service des contrats formateurs utilisait déjà ce mécanisme. Les images de logos, programmes et supports pédagogiques volontairement publiés restent inchangées.
13. Le stockage des documents entreprise conserve désormais le type détecté et la taille avant déplacement du fichier, plutôt que le type fourni par le navigateur et une lecture d'un chemin temporaire disparu.

### Matrice des URL historiques protégées

| Répertoire | Lecture autorisée |
| --- | --- |
| `uploads/pieces`, `uploads/session/pieces` | URL statique refusée ; routes de téléchargement existantes avec contrôle complet de rattachement |
| `uploads/emargements` | Administrateur de l'organisme, formateur affecté à la session, stagiaire pour sa propre signature |
| `uploads/proofs`, `uploads/depense` | Administrateur de l'organisme |
| `uploads/entreprise/docs` | Administrateur de l'organisme ou compte de l'entreprise destinataire |
| `uploads/pdf`, `uploads/devis`, `uploads/conventions` | Enregistrement de document identifié : administrateur, destinataire, formateur concerné ou délégation effective du portefeuille selon le type |
| `uploads/signatures/organisme`, `uploads/tampons/organisme` | Prévisualisation réservée à l'administrateur de l'organisme ; insertion PDF par lecture locale contrôlée |

Les accès invités par QR conservent leur mécanisme de jeton et leurs signatures stockées en données ; ils ne dépendent pas des images statiques d'émargement.

## Vérifications

- `tests/Integration/DossierDocumentSecurityTest.php` : 9 tests / 91 assertions ; base SQLite isolée et fichiers de test éphémères. Accès interorganismes, associations incohérentes, traversée de chemin, absence de création sur GET, téléchargement privé, upload de script refusé, conservation d'une pièce validée, signature PNG et archivage du PDF, refus anonyme sur fichiers existants, suspension, accès entreprise/formateur/commercial, images locales et confinement des liens symboliques vérifiés.
- `tests/Integration/FinanceNavigationTest.php` : attente obsolète corrigée pour les interfaces commerciales et OPCO désormais disponibles ; la suspension de l'adhésion reste testée.
- Tests `RegulatoryDocumentsTest`, `PedagogicalDurationDocumentsTest` et `ConventionDocumentTest` : 9 tests / 65 assertions après remplacement des images distantes.
- Validation du conteneur Symfony, de la syntaxe PHP des contrôleurs/services modifiés et des 16 modèles Twig PDF.
- Vérification Apache locale par le conteneur Docker : 11 fichiers fictifs existants, un dans chaque famille protégée (`emargements`, `proofs`, `depense`, `signatures/organisme`, `tampons/organisme`, `entreprise/docs`, `pdf`, `devis`, `conventions`, `pieces`, `session/pieces`), répondent 404 sans authentification et sans exposer leur contenu. Une page HTML témoin répond 200. Les fichiers témoins ont été supprimés. Ce contrôle doit être répété sur l'hébergement réel après déploiement.

## Réserves à traiter avant une garantie d'exploitation

- Le stockage physique historique demeure sous `public/uploads`. Sur Nginx, un proxy, un CDN ou un stockage externe, `.htaccess` n'est pas interprété : bloquer les deux familles de pièces, envoyer les familles protégées au contrôleur Symfony avant toute règle de fichier existant et interdire l'exécution des uploads. Vérifier les anciens caches et URL d'origine sans authentification. Un déplacement physique hors de la racine publique reste préférable et exige un plan de migration de tous les lecteurs, sans perte d'archives.
- Les archives PDF sans enregistrement correspondant, les associations contradictoires et les noms ambigus sont volontairement indisponibles depuis l'URL statique. Les examiner et les rattacher correctement avant restauration de l'accès ; ne pas créer une exception générale qui rouvrirait le répertoire.
- Les supports pédagogiques, contenus e-learning et photos dont la publication peut être intentionnelle n'ont pas été rendus privés indistinctement. Leur politique de partage, leurs droits de diffusion et leur éventuel contenu personnel doivent être vérifiés avec l'organisme.
- Les preuves de signature conservées correspondent à un consentement authentifié dans l'application. Aucun prestataire de signature qualifiée, horodatage qualifié ou qualification eIDAS n'est ajouté par ces correctifs.
- Les documents anciens déjà marqués signés mais privés de leur PDF ne peuvent pas être reconstruits et déclarés identiques après coup : restaurer l'original depuis une sauvegarde ou organiser une nouvelle signature.
- Les règles de conservation/purge, sauvegardes et restaurations, journaux complets de consultation/modification/suppression, revue périodique des droits, contrats de sous-traitance et traitement des demandes RGPD restent à vérifier avec les procédures et l'hébergement réels. Le code seul ne démontre pas leur exécution.
