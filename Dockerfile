FROM serversideup/php:8.4-fpm-nginx-alpine

USER root

# Defaults for production; can be overridden in Coolify.
# LOG_CHANNEL=stderr makes Laravel errors visible in the container runtime logs.
ENV AUTORUN_ENABLED=true \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1

# Install Node.js (LTS) and PHP extensions (intl; gd + exif for avatar resizing)
RUN apk add --no-cache nodejs npm && \
    install-php-extensions intl gd exif

WORKDIR /var/www/html

# Copy application files
COPY --chown=www-data:www-data . .

# Install PHP dependencies
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-progress \
    --prefer-dist

# Install Node.js dependencies and build frontend assets
RUN npm ci --silent && npm run build && rm -rf node_modules

# Custom startup scripts (run after serversideup's migrations, see 50-laravel-automations)
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
RUN docker-php-serversideup-s6-init

# Laravel scheduler as an extra s6 service, so no separate scheduler container is needed.
# s6-overlay 3.x: register service via user-bundles.d (user/contents.d in s6-rc.d is read-only at runtime).
COPY --chmod=755 docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
RUN mkdir -p /etc/s6-overlay/user-bundles.d && \
    touch /etc/s6-overlay/user-bundles.d/laravel-scheduler

# Ensure writable directories are owned by web user
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
