import { expect, type Page, test } from '@playwright/test';
import path from 'node:path';
import { frozenNow } from '../fixtures/env';
import { pages } from '../fixtures/pages';

// Default: straight into the documentation (docs/MODULE.md embeds these files)
const outputDir = path.resolve(__dirname, '..', process.env.E2E_SCREENSHOT_DIR ?? '../../docs/screenshots');

/** Waits until nothing loads or moves any more: network idle, web fonts, images and Livewire done */
async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(
            [...document.images].filter((img) => !img.complete).map((img) => new Promise((resolve) => {
                img.addEventListener('load', resolve, { once: true });
                img.addEventListener('error', resolve, { once: true });
            })),
        );
    });
    // Two animation frames: layout changes from the last script run are painted
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

const now = frozenNow();

test.beforeEach(async ({ page, baseURL }) => {
    // The browser shows the same day as the server (FullCalendar, relative dates in scripts)
    if (now) {
        await page.clock.setFixedTime(now);
    }
    // Only the app itself: external requests (map tiles, avatars of other services) would make the
    // pictures depend on the network
    const origin = new URL(baseURL!).origin;
    await page.route('**/*', (route) => (new URL(route.request().url()).origin === origin ? route.continue() : route.abort()));
});

for (const target of pages) {
    test(`${target.module}: ${target.name}`, async ({ page }) => {
        const response = await page.goto(target.path);
        expect(response, `response for ${target.path}`).not.toBeNull();
        expect(response!.status(), `HTTP status of ${target.path}`).toBeLessThan(400);
        // Still logged in: no redirect to the login page
        expect(new URL(page.url()).pathname).not.toMatch(/^\/anmelden/);

        await expect(page.getByText(target.ready).filter({ visible: true }).first()).toBeVisible();
        if (target.selector) {
            await expect(page.locator(target.selector).first()).toBeVisible();
        }
        await settle(page);

        await page.screenshot({
            path: path.join(outputDir, target.module, `${target.name}.png`),
            fullPage: true,
            animations: 'disabled',
            caret: 'hide',
            // The floating Offline status panel (extensions/Offline) would cover page content
            style: '.offline-pwa { visibility: hidden !important; } *, *::before, *::after { transition: none !important; }',
        });
    });
}
