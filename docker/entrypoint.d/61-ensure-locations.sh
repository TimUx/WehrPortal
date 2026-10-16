#!/bin/sh
# Runs on every container start (after the migrations): at least one Standort must exist.
php /var/www/html/artisan app:ensure-locations --no-interaction || echo "app:ensure-locations failed"
