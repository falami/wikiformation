#!/bin/bash
set -euo pipefail
umask 077

export PATH="$HOME/bin:$PATH"
export APP_ENV=prod APP_DEBUG=0
APP_DIR="${DEPLOY_APP_DIR:-$HOME/apps/wkformation}"
BACKUP_ROOT="${DEPLOY_BACKUP_DIR:-$HOME/backups/wkformation}"
cd "$APP_DIR"
APP_DIR="$(pwd -P)"
mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
BACKUP_ROOT="$(cd "$BACKUP_ROOT" && pwd -P)"
case "$BACKUP_ROOT/" in "$APP_DIR/"*) echo 'Backup directory must be outside the application.' >&2; exit 1;; esac

for tool in php git composer tar gzip flock sha256sum; do
  command -v "$tool" >/dev/null || { echo "Missing required tool: $tool" >&2; exit 1; }
done
DUMP_BIN="$(command -v mariadb-dump || command -v mysqldump || true)"
[[ -n "$DUMP_BIN" ]] || { echo 'Missing mariadb-dump or mysqldump.' >&2; exit 1; }
exec 9>"$BACKUP_ROOT/.deployment.lock"
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }

BACKUP_DIR="$(mktemp -d "$BACKUP_ROOT/$(date +%Y%m%d-%H%M%S)-XXXXXX")"
trap 'echo "Deployment interrupted. Inspect backup directory: $BACKUP_DIR" >&2' ERR
printf 'Backup before deployment: %s\n' "$BACKUP_DIR"
git rev-parse HEAD > "$BACKUP_DIR/revision.txt"

# Includes hidden configuration, dependencies and uploaded files. Runtime cache/logs
# are excluded. External storage and symlink targets require their own backups.
tar --exclude='./.git' --exclude='./var/cache' --exclude='./var/log' \
  -czf "$BACKUP_DIR/files.tar.gz.partial" -C "$APP_DIR" .
gzip -t "$BACKUP_DIR/files.tar.gz.partial"
mv "$BACKUP_DIR/files.tar.gz.partial" "$BACKUP_DIR/files.tar.gz"
php "$APP_DIR/scripts/backup-database.php" "$DUMP_BIN" "$BACKUP_DIR"
gzip "$BACKUP_DIR/database.sql"
gzip -t "$BACKUP_DIR/database.sql.gz"
(cd "$BACKUP_DIR" && sha256sum files.tar.gz database.sql.gz revision.txt > SHA256SUMS && sha256sum -c SHA256SUMS)
printf 'Backup completed at %s\n' "$(date -Iseconds)" > "$BACKUP_DIR/COMPLETE"

# No deployment operation is performed until both backups have succeeded.
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:clear --env=prod --no-debug --no-warmup
php bin/console cache:warmup --env=prod --no-debug
php bin/console app:habilitations:sync --env=prod --no-debug
chmod -R ug+rwX var
printf 'Deployment completed. Backup: %s\n' "$BACKUP_DIR"
