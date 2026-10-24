#!/usr/bin/env bash
# Runs once after the dev container is created. Safe to run again.
set -euo pipefail

cd "$(dirname "$0")/.."

set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s|^${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Codespaces serves port 8000 under its own https URL; locally it's plain localhost
if [ -n "${CODESPACE_NAME:-}" ]; then
    app_url="https://${CODESPACE_NAME}-8000.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
else
    app_url="http://localhost:8000"
fi

set_env APP_ENV local
set_env APP_DEBUG true
set_env APP_URL "${app_url}"
set_env DB_HOST 127.0.0.1
set_env DB_DATABASE wehrportal
set_env DB_USERNAME wehrportal
set_env DB_PASSWORD secret
set_env MAIL_MAILER log
set_env QUEUE_CONNECTION sync
set_env ADMIN_EMAILS admin@example.com

composer install --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build

if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate --no-interaction
fi

until pg_isready -h 127.0.0.1 -U wehrportal -d wehrportal >/dev/null 2>&1; do
    echo "Waiting for PostgreSQL ..."
    sleep 1
done

php artisan migrate --seed --force --no-interaction
php artisan app:ensure-admins

echo
echo "Fertig. App starten: php artisan serve --host=0.0.0.0"
echo "Login als admin@example.com, den Magic Link findest du in storage/logs/laravel.log"
