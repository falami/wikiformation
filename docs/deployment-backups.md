# Déploiement avec sauvegarde

Lancer `bash deploy.sh` sur le serveur. Un push Git seul ne déclenche pas de sauvegarde.

## Première installation

Copier **deploy.sh et scripts/backup-database.php** sur le serveur, aux mêmes emplacements dans `~/apps/wkformation`, avant le premier lancement. Remplacer également tout ancien script de déploiement enregistré dans cPanel par l'appel `bash "$HOME/apps/wkformation/deploy.sh"`. Un simple pull initial ne protège pas les migrations lancées manuellement.

## Contenu

Chaque exécution crée `~/backups/wkformation/DATE-HEURE-XXXXXX/` :

- `files.tar.gz` : application, dépendances, fichiers cachés de configuration et uploads locaux ; hors `.git`, `var/cache`, `var/log`.
- `database.sql.gz` : base MySQL/MariaDB configurée par l'application en production, avec procédures, événements et déclencheurs.
- `revision.txt`, `SHA256SUMS` et `COMPLETE` : révision avant déploiement, empreintes vérifiées et marqueur de réussite.

Un dossier sans `COMPLETE` est incomplet et ne permet pas d'autoriser le déploiement. Les identifiants SQL ne sont pas passés en arguments ; leur fichier temporaire privé est supprimé même en cas d'erreur PHP normale. Une interruption brutale du système peut laisser ce fichier privé dans le dossier de sauvegarde.

Le dump utilise `--single-transaction --skip-lock-tables`, après vérification que toutes les tables sont en InnoDB. Il évite le verrou global qui nécessite le privilège RELOAD sur MariaDB. Si une table utilise un autre moteur, le script s'arrête : une sauvegarde verrouillée avec les droits adaptés est nécessaire. Ne pas exécuter de changement de structure (ALTER, CREATE, DROP, RENAME, TRUNCATE) pendant l'export, y compris depuis un autre outil. Les migrations du déploiement démarrent seulement après la sauvegarde. Les droits d'export des procédures, événements et déclencheurs restent nécessaires. Les options DBAL personnalisées (notamment TLS) nécessitent une adaptation explicite.

Les fichiers et la base sont sauvegardés successivement : pour une restauration cohérente entre uploads et base sur un site très actif, suspendre les écritures et les workers pendant la sauvegarde. Les cibles de liens symboliques et les stockages externes ne sont pas inclus.

## Vérification et restauration

En cas d'échec de l'export, le diagnostic du client SQL s'affiche dans le terminal et dans `database-error.log` (privé, mot de passe masqué). Le déploiement reste bloqué. Ce diagnostic permet de distinguer un problème de droits, de connexion ou d'option incompatible ; ne pas lancer les migrations pour contourner cet échec.

Les contrôles de compression et d'empreinte détectent les archives tronquées, mais ne remplacent pas un essai de restauration. Tester régulièrement sur une base et un répertoire isolés : vérifier `sha256sum -c SHA256SUMS`, extraire l'archive et importer le SQL. Ne jamais tester une restauration directement en production.

Aucune purge automatique : surveiller l'espace disque et conserver une copie hors du serveur. Les archives contiennent des données et des secrets ; leur répertoire est privé (0700, fichiers 0600).

Prérequis : Bash, PHP, Composer, Git, tar, gzip, flock, sha256sum, mariadb-dump ou mysqldump. Les chemins peuvent être adaptés avec DEPLOY_APP_DIR et DEPLOY_BACKUP_DIR (ce dernier doit rester hors de l'application).
