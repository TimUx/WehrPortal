import { type Browser, expect, test as setup } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Logs in like a Kamerad: request the magic link on /anmelden, take the link from the mail
 * (MAIL_MAILER=log writes it to the Laravel log), confirm it and keep the session for all specs.
 */
const email = process.env.E2E_EMAIL ?? 'admin@example.com';
const mailLog = path.resolve(__dirname, '..', process.env.E2E_MAIL_LOG ?? '../../storage/logs/laravel.log');
const authFile = path.resolve(__dirname, '../.auth/admin.json');

const logSize = () => (fs.existsSync(mailLog) ? fs.statSync(mailLog).size : 0);

/** Magic link token from what was logged after `offset`; quoted-printable soft breaks are undone first */
function tokenAfter(offset: number): string | null {
    if (!fs.existsSync(mailLog)) {
        return null;
    }
    const fd = fs.openSync(mailLog, 'r');
    try {
        const length = fs.statSync(mailLog).size - offset;
        if (length <= 0) {
            return null;
        }
        const buffer = Buffer.alloc(length);
        fs.readSync(fd, buffer, 0, length, offset);
        const text = buffer.toString('utf8').replace(/=\r?\n/g, '').replace(/=3D/gi, '=');
        const matches = [...text.matchAll(/\/anmelden\/([A-Za-z0-9]{64})\b/g)];

        return matches.length ? matches[matches.length - 1][1] : null;
    } finally {
        fs.closeSync(fd);
    }
}

/** True if the session of an earlier run is still logged in (same database, cookie not expired) */
async function sessionStillValid(browser: Browser, baseURL: string): Promise<boolean> {
    if (!fs.existsSync(authFile)) {
        return false;
    }
    const context = await browser.newContext({ baseURL, storageState: authFile });
    try {
        const response = await context.request.get('/personalakte', { maxRedirects: 0 });

        return response.status() === 200;
    } finally {
        await context.close();
    }
}

setup('log in with a magic link', async ({ page, browser, baseURL }) => {
    // Reruns against the same database keep their session: the login rate limit (5 links per hour)
    // never decays while the server clock is frozen, and every login adds an entry to the admin dashboard
    if (await sessionStillValid(browser, baseURL!)) {
        return;
    }
    const offset = logSize();

    await page.goto('/anmelden');
    await page.locator('#email').fill(email);
    await page.getByRole('button', { name: 'Login-Link anfordern' }).click();
    await expect(page.getByText('Falls diese E-Mail-Adresse bekannt ist')).toBeVisible();

    let token: string | null = null;
    await expect.poll(() => (token = tokenAfter(offset)), { message: `magic link in ${mailLog}`, timeout: 15_000 }).not.toBeNull();

    await page.goto(`/anmelden/${token}`);
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/anmelden'));

    await page.context().storageState({ path: authFile });
});
