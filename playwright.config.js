// Test nel browser (Playwright): chat dell'assistente e messaggi in tempo reale, le parti in JavaScript che PHPUnit
// non può eseguire. Avvia da solo tutto ciò che serve, con i dati demo su un database SQLite separato:
// il database di sviluppo non viene toccato. Comando: npm run test:browser (ferma prima composer run dev).
import { defineConfig, devices } from '@playwright/test';
import { closeSync, mkdirSync, openSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const APP_PORT = 8100;
const AGENT_PORT = 8101;
// Gli stessi valori di Reverb usati da "npm run test:browser" per costruire il front-end (finiscono nel JS)
const REVERB = { key: 'presto-e2e-key', port: 8080 };

const database = fileURLToPath(new URL('./database/e2e.sqlite', import.meta.url));
closeSync(openSync(database, 'a'));

// Cartella storage separata: le foto dei dati demo e la cache dei test non finiscono in quella del sito
const storage = fileURLToPath(new URL('./storage/e2e', import.meta.url));
for (const dir of ['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs']) {
    mkdirSync(`${storage}/${dir}`, { recursive: true });
}

const env = {
    APP_ENV: 'local', // i dati demo si creano solo in locale
    APP_DEBUG: 'true',
    APP_URL: `http://127.0.0.1:${APP_PORT}`,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: database,
    LARAVEL_STORAGE_PATH: storage,
    SCOUT_DRIVER: 'collection',
    QUEUE_CONNECTION: 'sync',
    CACHE_STORE: 'database', // sessioni e cache nel database di prova: nessun file lasciato in storage/
    SESSION_DRIVER: 'database',
    MAIL_MAILER: 'log',
    LOG_CHANNEL: 'stderr',
    BROADCAST_CONNECTION: 'reverb',
    REVERB_APP_ID: 'presto-e2e',
    REVERB_APP_KEY: REVERB.key,
    REVERB_APP_SECRET: 'presto-e2e-secret',
    REVERB_HOST: '127.0.0.1',
    REVERB_PORT: String(REVERB.port),
    REVERB_SCHEME: 'http',
    AI_AGENT_URL: `http://127.0.0.1:${AGENT_PORT}`,
    AI_AGENT_TOKEN: 'presto-e2e-token',
    // La chat richiama l'API di Laravel mentre la richiesta del browser è ancora aperta
    PHP_CLI_SERVER_WORKERS: '4',
};

export default defineConfig({
    testDir: './tests/Browser',
    // Un database condiviso: i test girano uno alla volta
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: env.APP_URL,
        locale: 'it-IT',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            command: 'node tests/Browser/fake-agent.mjs',
            url: `http://127.0.0.1:${AGENT_PORT}/health`,
            env: { ...env, FAKE_AGENT_PORT: String(AGENT_PORT), LARAVEL_URL: env.APP_URL },
            reuseExistingServer: false,
        },
        {
            command: `php artisan reverb:start --host=127.0.0.1 --port=${REVERB.port}`,
            port: REVERB.port,
            // Reverb controlla la cache per i riavvii: in memoria, perché le tabelle nascono in parallelo (migrate:fresh)
            env: { ...env, CACHE_STORE: 'array' },
            reuseExistingServer: false,
        },
        {
            // Dati demo da zero a ogni esecuzione, poi il sito
            command: `php artisan migrate:fresh --seed --force && php artisan serve --host=127.0.0.1 --port=${APP_PORT} --no-reload`,
            url: `${env.APP_URL}/up`,
            env,
            timeout: 180_000,
            reuseExistingServer: false,
        },
    ],
});
