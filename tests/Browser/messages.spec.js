import { expect, test } from '@playwright/test';
import { DEMO, GIULIA, expectNotReloaded, login, markPage, waitForChannel } from './helpers.js';

// Due persone, due browser separati (sessioni diverse): Giulia compra, l'utente demo vende
async function twoUsers(browser) {
    const seller = await (await browser.newContext()).newPage();
    const buyer = await (await browser.newContext()).newPage();
    await login(seller, DEMO);
    await login(buyer, GIULIA);

    // La conversazione della richiesta d'acquisto che Giulia ha inviato all'utente demo
    await seller.goto('/vendite');
    const href = await seller.locator('a[href*="/richieste/"][href$="/messaggi"]').first().getAttribute('href');
    const id = href.match(/\/richieste\/(\d+)\/messaggi/)[1];

    return { seller, buyer, id, url: `/richieste/${id}/messaggi` };
}

test('un messaggio arriva all\'altra persona senza ricaricare la pagina', async ({ browser }) => {
    const { seller, buyer, id, url } = await twoUsers(browser);
    await seller.goto(url);
    await buyer.goto(url);
    await waitForChannel(seller, `conversations.${id}`);
    await markPage(seller);

    const text = `Passo sabato alle 10? (${Date.now()})`;
    await buyer.locator('#conversation-body').fill(text);
    await buyer.getByRole('button', { name: 'Invia' }).click();

    // Compare a chi l'ha scritto e, tramite Reverb, al venditore che ha la chat aperta
    await expect(buyer.getByText(text)).toBeVisible();
    await expect(seller.getByText(text)).toBeVisible();
    await expectNotReloaded(seller);
});

test('su un\'altra pagina il contatore dei non letti sale dal vivo', async ({ browser }) => {
    const { seller, buyer, url } = await twoUsers(browser);
    await seller.goto('/annunci');
    const userId = await seller.locator('[data-user-id]').getAttribute('data-user-id');
    await waitForChannel(seller, `App.Models.User.${userId}`);
    await markPage(seller);

    const badge = seller.locator('[data-unread-badge="sales"]').first();
    const before = Number((await badge.locator('[data-unread-count]').textContent()) || 0);

    await buyer.goto(url);
    await buyer.locator('#conversation-body').fill(`Ancora disponibile? (${Date.now()})`);
    await buyer.getByRole('button', { name: 'Invia' }).click();

    // Il contatore sta nel menu dell'avatar (chiuso): conta il numero e che non sia più nascosto
    await expect(badge.locator('[data-unread-count]')).toHaveText(String(before + 1));
    await expect(badge).not.toHaveAttribute('hidden');
    await expectNotReloaded(seller);
});
