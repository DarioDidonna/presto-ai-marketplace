#!/bin/sh
# Comune a sito, coda e Reverb: una APP_KEY generata al primo avvio e salvata nel volume di storage,
# così i tre container usano la stessa chiave e nessuna chiave finisce nel repository.
set -e

if [ -z "$APP_KEY" ]; then
    key_file=storage/app/private/docker-app-key
    if [ ! -s "$key_file" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$key_file"
    fi
    APP_KEY="$(cat "$key_file")"
    export APP_KEY
fi

exec "$@"
