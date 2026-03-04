#!/bin/bash
set -euo pipefail

cd "$HOME/apps/wkformation"

# ✅ utilise TON composer (pas celui de cPanel)
export PATH="$HOME/bin:$PATH"

git pull

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

php bin/console doctrine:migrations:migrate --no-interaction

# ✅ clear sans warmup (on warm ensuite explicitement)
php bin/console cache:clear --env=prod --no-debug --no-warmup
php bin/console cache:warmup --env=prod --no-debug

chmod -R ug+rwX var