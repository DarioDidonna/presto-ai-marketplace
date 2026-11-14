# Immagine unica di Laravel per la demo con Docker Compose: la stessa immagine fa da sito, coda e Reverb.
# Pensata per provare il progetto in locale (APP_ENV=local, dati demo), non per la produzione.

# 1. Front-end: build di Vite (CSS, JS, icone). Le variabili di Reverb finiscono nel JS del browser.
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
ARG VITE_APP_NAME="Presto.it"
ARG VITE_REVERB_APP_KEY=presto-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8080
ARG VITE_REVERB_SCHEME=http
RUN npm run build

# 2. PHP 8.4 con le estensioni usate dal progetto (GD per le foto, EXIF per raddrizzarle, MySQL)
FROM php:8.4-cli-bookworm
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql gd exif intl zip bcmath pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

# Dipendenze prima del codice: cambiare un file PHP non rifà l'installazione.
# Con le dipendenze di sviluppo: i dati demo usano Faker.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize \
    && ln -sfn ../storage/app/public public/storage \
    && chmod +x docker/*.sh \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["docker/start-app.sh"]
