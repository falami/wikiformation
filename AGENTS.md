# Environnement de développement

L’utilisateur utilise exclusivement Docker et n’utilisera plus MAMP.
Le chemin historique `/Applications/MAMP/htdocs/wikiformation` est seulement l’emplacement du dépôt : il ne décrit pas les services utilisés.

- Exécuter PHP, Symfony, Composer, les tests et les migrations dans le service Docker Compose `app` : `docker compose exec -T app ...`.
- MySQL est le service `database` du projet Compose. Ne pas utiliser les binaires, ports, sockets ou services MAMP.
- L’application locale est accessible à `http://localhost:8888` (port publié par Docker).
- Vérifier l’état avec `docker compose ps`. En cas de restriction sandbox sur le socket Docker, demander l’escalade de l’outil plutôt que basculer vers MAMP ou un PHP hôte.
- Pour une correction de schéma, examiner les migrations appliquées et exécuter uniquement celles nécessaires à la demande. Ne jamais remplacer cela par une mise à jour globale forcée du schéma.
