import { expect, test } from '@playwright/test';
import { DEMO, expectNotReloaded, login, markPage } from './helpers.js';

test('la chat risponde con annunci veri e dalla scheda si aggiunge al carrello senza ricaricare', async ({ page }) => {
    await login(page, DEMO);
    await page.goto('/');

    // Il carrello demo ha già due annunci
    const cartBadge = page.locator('[data-cart-icon] .cart-badge');
    await expect(cartBadge).toHaveText('2');
    await markPage(page);

    await page.getByRole('button', { name: "Apri l'assistente acquisti" }).click();
    const panel = page.locator('#assistantPanel');
    await expect(panel).toBeVisible();

    await panel.locator('#assistantInput').fill('Cerco uno zaino');
    await panel.locator('#assistantInput').press('Enter');

    // Messaggio dell'utente, risposta e scheda dell'annuncio trovato tramite l'API di Laravel
    await expect(panel.getByText('Cerco uno zaino')).toBeVisible();
    await expect(panel.getByText('Ho trovato 1 annunci per "zaino".')).toBeVisible();
    const card = panel.locator('.assistant-card', { hasText: 'Zaino da città impermeabile' });
    await expect(card).toBeVisible();
    await expect(card.getByRole('link').first()).toHaveAttribute('href', /\/annunci\/\d+$/);

    await card.getByRole('button', { name: /Aggiungi al carrello: Zaino da città impermeabile/ }).click();

    // Contatore della navbar aggiornato dall'evento cart-updated, scheda segnata "Nel carrello"
    await expect(cartBadge).toHaveText('3');
    await expect(card.getByRole('link', { name: 'Nel carrello' })).toBeVisible();
    await expectNotReloaded(page);

    // Salvato davvero: il carrello lo mostra
    await page.goto('/carrello');
    await expect(page.locator('#main-content').getByRole('link', { name: 'Zaino da città impermeabile', exact: true })).toBeVisible();
});

test('la chat dice quando non trova nulla, senza schede', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: "Apri l'assistente acquisti" }).click();
    const panel = page.locator('#assistantPanel');

    await panel.locator('#assistantInput').fill('Cerco un elicottero');
    await panel.locator('#assistantInput').press('Enter');

    await expect(panel.getByText('Nessun annuncio per "elicottero".')).toBeVisible();
    await expect(panel.locator('.assistant-card')).toHaveCount(0);
});
