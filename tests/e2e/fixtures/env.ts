import fs from 'node:fs';
import path from 'node:path';

/**
 * The frozen "now" of the run: E2E_FROZEN_NOW from the environment, else from the app's .env (the
 * server reads the same value there, extensions/E2e). Empty = the real clock.
 */
export function frozenNow(): Date | null {
    let value = process.env.E2E_FROZEN_NOW;
    if (value === undefined) {
        const envFile = path.resolve(__dirname, '../../../.env');
        const line = fs.existsSync(envFile) ? fs.readFileSync(envFile, 'utf8').match(/^E2E_FROZEN_NOW=["']?([^"'\r\n]*)/m) : null;
        value = line?.[1];
    }
    if (!value) {
        return null;
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        throw new Error(`E2E_FROZEN_NOW is not a valid date: ${value}`);
    }

    return date;
}
