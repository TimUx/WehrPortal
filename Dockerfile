FROM serversideup/php:8.5-fpm-nginx-alpine

USER root

# Defaults for production; can be overridden in Coolify.
# LOG_CHANNEL=stderr makes Laravel errors visible in the container runtime logs.
# TRUSTED_PROXY=off: the base image's default ("cloudflare") lets nginx take the visitor's address
# from the CF-Connecting-IP header of any private network, so everyone behind another proxy could
# make it up. Laravel reads X-Forwarded-For from the trusted proxies instead (config/trustedproxy.php).
# Behind Cloudflare set TRUSTED_PROXY=cloudflare.
ENV AUTORUN_ENABLED=true \
    LOG_CHANNEL=stderr \
    PHP_OPCACHE_ENABLE=1 \
    TRUSTED_PROXY=off

# Security fixes of the Alpine packages (e.g. libX11 for gd, nghttp2) that the base image doesn't have yet,
# then Node.js (LTS) and PHP extensions (intl; gd + exif for avatar resizing)
RUN apk upgrade --no-cache && \
    apk add --no-cache 'curl>=8.23.0-r0' 'libcurl>=8.23.0-r0' nodejs npm && \
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
# Node is only needed for the build; it does not stay in the running image
RUN npm ci --silent && npm run build && rm -rf node_modules && apk del nodejs npm

# Custom startup scripts (run after serversideup's migrations, see 50-laravel-automations)
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/
RUN docker-php-serversideup-s6-init

# Laravel scheduler as an extra s6 service, so no separate scheduler container is needed.
# s6-overlay 3.2.3+: a service only starts when it is listed in the "user" bundle, which lives in
# user-bundles.d/user/contents.d. `test -d` fails the build if the base image moves it again,
# instead of leaving the scheduler silently down.
COPY --chmod=755 docker/s6-rc.d/ /etc/s6-overlay/s6-rc.d/
RUN test -d /etc/s6-overlay/user-bundles.d/user/contents.d && \
    touch /etc/s6-overlay/user-bundles.d/user/contents.d/laravel-scheduler

# Security headers come from the app; see the file for what the base image's version broke
COPY --chown=www-data:www-data docker/nginx/security.conf /etc/nginx/server-opts.d/security.conf

# Ensure writable directories are owned by web user
RUN chown -R www-data:www-data storage bootstrap/cache

USER www-data
