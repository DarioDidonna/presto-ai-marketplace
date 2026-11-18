import { expect } from '@playwright/test';

// Account dei dati demo (DemoSeeder), password "password"
export const DEMO = 'demo@presto.it';
export const GIULIA = 'giulia.romano@presto.it';

export async function login(page, email) {
    await page.goto('/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill('password');
    await page.locator('form:has(#email) button[type="submit"]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

// Aspetta che Echo sia collegato a Reverb e iscritto al canale privato: un evento inviato prima andrebbe perso
export async function waitForChannel(page, channel) {
    await page.waitForFunction(
        (name) => window.Echo?.connector?.pusher?.channel(`private-${name}`)?.subscribed === true,
        channel,
    );
}

// Segno lasciato nella pagina: se c'è ancora dopo l'azione, la pagina non è stata ricaricata
export async function markPage(page) {
    await page.evaluate(() => {
        window.__notReloaded = true;
    });
}

export async function expectNotReloaded(page) {
    expect(await page.evaluate(() => window.__notReloaded === true)).toBe(true);
}
