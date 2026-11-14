#!/bin/sh
# Container "app": prepara il database e avvia il sito. Coda e Reverb partono solo quando il sito risponde
# (healthcheck su /up), quindi trovano le tabelle già pronte.
set -e

php artisan migrate --force

# Dati demo una volta sola: il segno resta nel volume di storage (docker compose down -v riparte da zero)
seeded=storage/app/private/docker-seeded
if [ ! -f "$seeded" ]; then
    php artisan db:seed --force
    # Le foto demo passano dalla stessa catena di quelle caricate: volti oscurati dall'agente, poi miniature
    php artisan app:analyze-images
    touch "$seeded"
fi

# Più processi: la chat AI richiama le API di Laravel mentre la richiesta del browser è ancora aperta
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
