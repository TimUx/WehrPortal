import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

/**
 * E2E smoke tests that also take the documentation screenshots (docs-extended/SCREENSHOTS.md).
 * Expects a running app with the ScreenshotSeeder data at E2E_BASE_URL.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000';

export default defineConfig({
    testDir: './specs',
    outputDir: './test-results',
    // One browser after the other: the pages share one seeded database and one login
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 15_000 },
    reporter: process.env.CI
        ? [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]]
        : [['list']],
    use: {
        baseURL,
        // Fixed viewport, scale, locale and time zone: the same page gives the same picture
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        locale: 'de-DE',
        timezoneId: 'Europe/Berlin',
        colorScheme: 'light',
        reducedMotion: 'reduce',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1, storageState: path.join(__dirname, '.auth/admin.json') },
            dependencies: ['setup'],
        },
    ],
});
