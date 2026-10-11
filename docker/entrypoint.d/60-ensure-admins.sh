#!/bin/sh
# Runs on every container start (after serversideup's Laravel automations/migrations).
# Creates or activates the admin accounts listed in ADMIN_EMAILS.
if [ -n "$ADMIN_EMAILS" ]; then
    php /var/www/html/artisan app:ensure-admins --no-interaction || echo "app:ensure-admins failed"
fi
