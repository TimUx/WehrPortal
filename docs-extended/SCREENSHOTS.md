# E2E-Test und Screenshots

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md) · [Fork-Leitfaden](../FORK.md)

Der Workflow `.github/workflows/screenshots.yml` baut eine vollständige Testinstallation mit Beispieldaten auf, meldet sich im Browser an und fotografiert ausgewählte Seiten. Er dient zugleich als Smoke-Test vor Releases: Lädt eine Seite nicht, endet sie mit Fehler oder führt sie zurück zur Anmeldung, schlägt der Lauf fehl.

## Inhalt

- [Ablauf](#ablauf)
- [Seiten und Beispieldaten](#seiten-und-beispieldaten)
- [In GitHub Actions](#in-github-actions)
- [Lokal ausführen](#lokal-ausführen)
- [Stabile Bilder](#stabile-bilder)

## Ablauf

1. PostgreSQL 18 (wie in den Tests), PHP 8.4, Composer- und npm-Abhängigkeiten, `npm run build`.
2. `.env` aus dem Profil `tests/e2e/.env.e2e` (Datenbank `openwehr_e2e`, `APP_URL=http://127.0.0.1:8000`, `MAIL_MAILER=log`, Queue `sync`).
3. `php artisan migrate:fresh --seed --seeder="Database\\Seeders\\ScreenshotSeeder"` – leere Datenbank, alle Migrationen, Beispieldaten.
4. `php artisan serve` auf `127.0.0.1:8000`.
5. Playwright (`tests/e2e`, eigenes `package.json`, nur Chromium): Anmeldung über den echten Magic-Link-Ablauf – E-Mail auf `/anmelden` eingeben, den Link aus der geloggten Mail (`storage/logs/laravel.log`) lesen, bestätigen. Die Sitzung gilt für alle weiteren Seiten.
6. Pro Seite: HTTP-Status unter 400, keine Umleitung zu `/anmelden`, erwarteter Inhalt sichtbar, dann Screenshot nach `tests/e2e/screenshots/<modul>/<seite>.png`.

## Seiten und Beispieldaten

Die Seitenliste steht in `tests/e2e/fixtures/pages.ts` (Modul, Name, Pfad, erwarteter Text, optional ein CSS-Selektor). Weitere Seiten dort ergänzen; fehlen dafür Daten, gehören sie in `database/seeders/ScreenshotSeeder.php`.

| Modul | Seite | Datei |
|---|---|---|
| Kern | Startseite `/` | `core/startseite.png` |
| Fahrzeugbuchung | Kalender `/fahrzeugbuchung` | `fahrzeugbuchung/kalender.png` |
| Fahrzeugbuchung | Meine Buchungen | `fahrzeugbuchung/meine-buchungen.png` |
| Kleiderkammer | Übersicht `/kleiderkammer` | `kleiderkammer/uebersicht.png` |
| Anwesenheit (Fork) | Listen `/anwesenheit` | `anwesenheit/listen.png` |
| Hydrantenkontrolle (Fork) | Hydranten `/hydrantenkontrolle/hydranten` | `hydrantenkontrolle/hydranten.png` |
| Admin | Dashboard `/admin` | `admin/dashboard.png` |

`ScreenshotSeeder` ruft die Demo-Seeder (Fahrzeuge, Abteilungen, Admin `admin@example.com`, Kleiderkammer mit erfundenen Kameraden) auf, schaltet die fotografierten Module ein (`ScreenshotSeeder::MODULES`, mit automatischer Freischaltung) und legt einige Buchungen, Hydranten und eine Anwesenheitsliste an. Nur erfundene Personen; nie auf einer echten Datenbank ausführen – `migrate:fresh` löscht alles.

## In GitHub Actions

- **Auslöser:** manuell (**Actions → E2E Screenshots → Run workflow**), bei jedem Push auf `extended` und als wiederverwendbarer Workflow (`workflow_call`), z. B. als Release-Gate:

  ```yaml
  jobs:
    e2e:
      uses: ./.github/workflows/screenshots.yml
    release:
      needs: e2e
      # …
  ```

- **Artefakte:** `screenshots` (immer, 30 Tage) mit dem Ordner `tests/e2e/screenshots/`; bei Fehlern zusätzlich `e2e-report` (Playwright-Report, Traces, Fehlerbilder, `storage/logs/` der Wegwerf-Installation, 7 Tage).
- Keine Secrets nötig. Die Bilder werden nicht ins Repository committet.

## Lokal ausführen

Voraussetzung: PostgreSQL mit Benutzer `openwehr`/`secret` auf `127.0.0.1:5432` (z. B. der Dev-Container oder `docker run -d -e POSTGRES_DB=openwehr_e2e -e POSTGRES_USER=openwehr -e POSTGRES_PASSWORD=secret -p 5432:5432 postgres:18`). Die vorhandene `.env` vorher sichern.

```bash
cp tests/e2e/.env.e2e .env && php artisan key:generate
npm ci && npm run build
php artisan migrate:fresh --seed --seeder="Database\\Seeders\\ScreenshotSeeder" --force
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 &

cd tests/e2e
npm ci && npx playwright install --with-deps chromium
npx playwright test
```

Variablen: `E2E_BASE_URL` (Standard `http://127.0.0.1:8000`), `E2E_EMAIL` (Standard `admin@example.com`), `E2E_MAIL_LOG` (Pfad zum Mail-Log relativ zu `tests/e2e`, Standard `../../storage/logs/laravel.log`), `E2E_SCREENSHOT_DIR` (Standard `screenshots`). Der Magic Link ist auf 5 Anforderungen pro Stunde und Adresse begrenzt; bei vielen Läufen hintereinander die Datenbank mit `migrate:fresh` neu aufsetzen (setzt auch den Zähler zurück).

## Stabile Bilder

- Feste Fenstergröße 1440×900, Skalierung 1, `de-DE`, Zeitzone `Europe/Berlin`, helles Farbschema, reduzierte Bewegung, ein Worker.
- Vor dem Bild: sichtbarer Zielinhalt, Netzwerk ruhig (`networkidle`), Schriften und Bilder geladen, zwei Animation-Frames; Animationen und Cursor werden ausgeblendet. Keine festen Wartezeiten.
- Das schwebende Offline-Statusfeld (`extensions/Offline`) wird nur im Bild ausgeblendet.
- Die Buchungen liegen auf festen Wochentagen der aktuellen Woche, damit die Wochenansicht des Kalenders immer gefüllt ist. Datumsangaben, „Letzte Anmeldungen“ und Uhrzeiten im Admin-Dashboard ändern sich naturgemäß von Lauf zu Lauf.
