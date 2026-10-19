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
2. `.env` aus dem Profil `tests/e2e/.env.e2e` (Datenbank `openwehr_e2e`, `APP_URL=http://127.0.0.1:8000`, `MAIL_MAILER=log`, Queue `sync`, eingefrorene Uhr `E2E_FROZEN_NOW`).
3. `php artisan migrate:fresh --seed --seeder="Database\\Seeders\\ScreenshotSeeder"` – leere Datenbank, alle Migrationen, Beispieldaten für alle Module.
4. `php artisan serve` auf `127.0.0.1:8000`.
5. Playwright (`tests/e2e`, eigenes `package.json`, nur Chromium): Anmeldung über den echten Magic-Link-Ablauf – E-Mail auf `/anmelden` eingeben, den Link aus der geloggten Mail (`storage/logs/laravel.log`) lesen, bestätigen. Die Sitzung gilt für alle weiteren Seiten; ein erneuter Lauf gegen dieselbe Datenbank verwendet sie weiter, solange sie gültig ist.
6. Pro Seite: HTTP-Status unter 400, keine Umleitung zu `/anmelden`, erwarteter Inhalt sichtbar, dann Screenshot nach `tests/e2e/screenshots/<modul>/<seite>.png`.

## Seiten und Beispieldaten

Die Seitenliste steht in `tests/e2e/fixtures/pages.ts` (Modul, Name, Pfad, erwarteter Text, optional ein CSS-Selektor). Weitere Seiten dort ergänzen; fehlen dafür Daten, gehören sie in `database/seeders/ScreenshotSeeder.php`.

| Bereich | Seiten (Datei `<modul>/<seite>.png`) |
|---|---|
| Kern | `core/startseite` (`/`), `core/personalakte`, `core/bestand`, `admin/dashboard` (`/admin`) |
| Fahrzeugbuchung | `kalender` (`/fahrzeugbuchung`), `meine-buchungen` |
| Kleiderkammer | `dashboard` (`/admin/kleiderkammer`) |
| Mitteilungen, Kalender, Mängelmanagement | `mitteilungen/uebersicht`, `kalender/termine`, `maengelmanagement/maengel` |
| Ausrüstung, Wartung, Schichtplan | `ausruestung/geraete`, `wartung/faellig`, `schichtplan/plaene` |
| Lehrgänge, Übungsdienste | `lehrgaenge/ausbildungsstand`, `uebungsdienste/planer` |
| Einsatzbericht | `einsaetze`, `statistik` |
| Finanzen, Verbandbuch, Expositionen | `finanzen/budget`, `verbandbuch/eintraege`, `expositionen/meldeliste` |
| Führerscheine, Unterweisungen, Atemschutz | `fuehrerscheine/pruefen`, `unterweisungen/uebersicht`, `atemschutz/geraetetraeger` |
| Fahrzeuge, Fahrtenbuch | `fahrzeuge/fahrzeuge`, `fahrzeuge/fristen`, `fahrtenbuch/fahrzeuge` |
| Wasserkarte, Objektpläne | `wasserkarte/karte`, `objektplaene/objekte` |
| Personalverwaltung, Schlüssel | `personalverwaltung/kameraden`, `schluessel/schluessel` |
| Gefahrenmatrix, Gefahrstoffe | `gefahrenmatrix/matrix`, `gefahrstoffe/datenbank` |
| Fork-Erweiterungen | `anwesenheit/listen`, `hydrantenkontrolle/jahreskontrollen`, `hydrantenkontrolle/hydranten`, `dateien/bereiche`, `locations/standorte` (`/admin/standorte`) |

`ScreenshotSeeder` ruft die Demo-Seeder (Fahrzeuge, Abteilungen, Admin `admin@example.com`, Kameraden, Kleiderkammer) auf, schaltet **alle** registrierten Module und Erweiterungen ein (`ScreenshotSeeder::modules()`, mit automatischer Freischaltung) und legt pro Modul ein paar Datensätze an (Buchungen, Mitteilungen, Termine, Mängel, Einsatzberichte, Hydranten mit laufender Jahreskontrolle, zweiter Standort …). Er ist wiederholbar (`firstOrCreate`/`updateOrCreate`). Nur erfundene Personen; nie auf einer echten Datenbank ausführen – `migrate:fresh` löscht alles.

Nicht fotografiert: `/personalakte/nachweise` (der QR-Code hängt vom zufälligen `APP_KEY` ab).

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

Variablen: `E2E_BASE_URL` (Standard `http://127.0.0.1:8000`), `E2E_EMAIL` (Standard `admin@example.com`), `E2E_MAIL_LOG` (Pfad zum Mail-Log relativ zu `tests/e2e`, Standard `../../storage/logs/laravel.log`), `E2E_SCREENSHOT_DIR` (Standard `screenshots`). Der Magic Link ist auf 5 Anforderungen pro Stunde und Adresse begrenzt, und bei eingefrorener Uhr läuft diese Stunde nie ab. Wiederholte Läufe verwenden deshalb die gespeicherte Sitzung (`tests/e2e/.auth/admin.json`); nach `migrate:fresh` wird neu angemeldet. Für byte-gleiche Bilder wie in CI vor dem Lauf `migrate:fresh` ausführen (jede Anmeldung erscheint im Admin-Dashboard unter „Letzte Anmeldungen“).

## Stabile Bilder

Zwei Läufe auf frisch aufgesetzten Installationen liefern byte-gleiche PNGs (gleicher Runner/Browser). Dafür:

- **Eingefrorene Uhr:** `E2E_FROZEN_NOW` (ISO 8601 mit Offset, z. B. `2026-10-14T10:00:00+02:00`). Der Server hält die Zeit über die Erweiterung `extensions/E2e` an (`Carbon::setTestNow`, nur wenn gesetzt, nie in `production` und nie in Pest); der Browser bekommt dieselbe Zeit (`page.clock.setFixedTime`, Wert aus der Umgebung oder der `.env`). Beispieldaten, „vor 2 Tagen“, Kalenderwoche und Fristen sind so in jedem Lauf gleich. Leer lassen = echte Uhr.
- Weil Cookies vom eingefrorenen Datum aus ablaufen, ist `SESSION_LIFETIME` im Profil sehr lang.
- Feste Fenstergröße 1440×900, Skalierung 1, `de-DE`, Zeitzone `Europe/Berlin`, helles Farbschema, reduzierte Bewegung, ein Worker; Chromium mit Software-Rendering ohne Partial Raster (gleiche Kantenglättung).
- Nur Anfragen an die App selbst; externe (Kartenkacheln der Wasserkarte u. Ä.) werden abgebrochen, damit das Netz die Bilder nicht verändert.
- Vor dem Bild: sichtbarer Zielinhalt, Netzwerk ruhig (`networkidle`), Schriften und Bilder geladen, zwei Animation-Frames; Animationen, Übergänge und Cursor sind aus. Keine festen Wartezeiten.
- Das schwebende Offline-Statusfeld (`extensions/Offline`) wird nur im Bild ausgeblendet.
