# E2E-Test und Screenshots

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md) · [Migration und Betrieb](../MIGRATION.md)

Der Workflow `.github/workflows/screenshots.yml` baut eine vollständige Testinstallation mit Beispieldaten auf, meldet sich im Browser an und fotografiert jedes Modul. Die Bilder liegen in `docs-extended/screenshots/` und sind in die Dokumentation eingebunden (README bei jedem Modul, [Galerie](#galerie)); `.github/workflows/docs-screenshots.yml` hält sie aktuell. Der Lauf dient zugleich als Smoke-Test vor Releases: Lädt eine Seite nicht, endet sie mit Fehler oder führt sie zurück zur Anmeldung, schlägt der Lauf fehl.

## Inhalt

- [Galerie](#galerie)
- [Ablauf](#ablauf)
- [Seiten und Beispieldaten](#seiten-und-beispieldaten)
- [In GitHub Actions](#in-github-actions)
- [Lokal ausführen](#lokal-ausführen)
- [Stabile Bilder](#stabile-bilder)

## Galerie

Alle Bilder der letzten Erzeugung (Ordner `docs-extended/screenshots/`, Klick öffnet das Bild in voller Größe). In der [README](../README.md) steht jedes Bild zusätzlich beim beschriebenen Modul.

| | |
|---|---|
| <a href="screenshots/core/startseite.png"><img src="screenshots/core/startseite.png" alt="core: startseite" width="400"></a><br>`core/startseite` · `/` | <a href="screenshots/core/personalakte.png"><img src="screenshots/core/personalakte.png" alt="core: personalakte" width="400"></a><br>`core/personalakte` · `/personalakte` |
| <a href="screenshots/core/bestand.png"><img src="screenshots/core/bestand.png" alt="core: bestand" width="400"></a><br>`core/bestand` · `/bestand` | <a href="screenshots/admin/dashboard.png"><img src="screenshots/admin/dashboard.png" alt="admin: dashboard" width="400"></a><br>`admin/dashboard` · `/admin` |
| <a href="screenshots/fahrzeugbuchung/kalender.png"><img src="screenshots/fahrzeugbuchung/kalender.png" alt="fahrzeugbuchung: kalender" width="400"></a><br>`fahrzeugbuchung/kalender` · `/fahrzeugbuchung` | <a href="screenshots/fahrzeugbuchung/meine-buchungen.png"><img src="screenshots/fahrzeugbuchung/meine-buchungen.png" alt="fahrzeugbuchung: meine-buchungen" width="400"></a><br>`fahrzeugbuchung/meine-buchungen` · `/fahrzeugbuchung/meine-buchungen` |
| <a href="screenshots/kleiderkammer/dashboard.png"><img src="screenshots/kleiderkammer/dashboard.png" alt="kleiderkammer: dashboard" width="400"></a><br>`kleiderkammer/dashboard` · `/admin/kleiderkammer` | <a href="screenshots/mitteilungen/uebersicht.png"><img src="screenshots/mitteilungen/uebersicht.png" alt="mitteilungen: uebersicht" width="400"></a><br>`mitteilungen/uebersicht` · `/mitteilungen` |
| <a href="screenshots/kalender/termine.png"><img src="screenshots/kalender/termine.png" alt="kalender: termine" width="400"></a><br>`kalender/termine` · `/kalender` | <a href="screenshots/maengelmanagement/maengel.png"><img src="screenshots/maengelmanagement/maengel.png" alt="maengelmanagement: maengel" width="400"></a><br>`maengelmanagement/maengel` · `/maengelmanagement` |
| <a href="screenshots/ausruestung/geraete.png"><img src="screenshots/ausruestung/geraete.png" alt="ausruestung: geraete" width="400"></a><br>`ausruestung/geraete` · `/ausruestung/geraete` | <a href="screenshots/wartung/faellig.png"><img src="screenshots/wartung/faellig.png" alt="wartung: faellig" width="400"></a><br>`wartung/faellig` · `/wartung` |
| <a href="screenshots/schichtplan/plaene.png"><img src="screenshots/schichtplan/plaene.png" alt="schichtplan: plaene" width="400"></a><br>`schichtplan/plaene` · `/schichtplan` | <a href="screenshots/lehrgaenge/ausbildungsstand.png"><img src="screenshots/lehrgaenge/ausbildungsstand.png" alt="lehrgaenge: ausbildungsstand" width="400"></a><br>`lehrgaenge/ausbildungsstand` · `/lehrgaenge/ausbildungsstand` |
| <a href="screenshots/uebungsdienste/planer.png"><img src="screenshots/uebungsdienste/planer.png" alt="uebungsdienste: planer" width="400"></a><br>`uebungsdienste/planer` · `/uebungsdienste` | <a href="screenshots/einsatzbericht/einsaetze.png"><img src="screenshots/einsatzbericht/einsaetze.png" alt="einsatzbericht: einsaetze" width="400"></a><br>`einsatzbericht/einsaetze` · `/einsatzbericht` |
| <a href="screenshots/einsatzbericht/statistik.png"><img src="screenshots/einsatzbericht/statistik.png" alt="einsatzbericht: statistik" width="400"></a><br>`einsatzbericht/statistik` · `/einsatzbericht/statistik` | <a href="screenshots/finanzen/budget.png"><img src="screenshots/finanzen/budget.png" alt="finanzen: budget" width="400"></a><br>`finanzen/budget` · `/finanzen` |
| <a href="screenshots/verbandbuch/eintraege.png"><img src="screenshots/verbandbuch/eintraege.png" alt="verbandbuch: eintraege" width="400"></a><br>`verbandbuch/eintraege` · `/verbandbuch` | <a href="screenshots/expositionen/meldeliste.png"><img src="screenshots/expositionen/meldeliste.png" alt="expositionen: meldeliste" width="400"></a><br>`expositionen/meldeliste` · `/expositionen/verwaltung` |
| <a href="screenshots/fuehrerscheine/pruefen.png"><img src="screenshots/fuehrerscheine/pruefen.png" alt="fuehrerscheine: pruefen" width="400"></a><br>`fuehrerscheine/pruefen` · `/fuehrerscheine/pruefen` | <a href="screenshots/unterweisungen/uebersicht.png"><img src="screenshots/unterweisungen/uebersicht.png" alt="unterweisungen: uebersicht" width="400"></a><br>`unterweisungen/uebersicht` · `/unterweisungen/uebersicht` |
| <a href="screenshots/atemschutz/geraetetraeger.png"><img src="screenshots/atemschutz/geraetetraeger.png" alt="atemschutz: geraetetraeger" width="400"></a><br>`atemschutz/geraetetraeger` · `/atemschutz/geraetetraeger` | <a href="screenshots/fahrzeuge/fahrzeuge.png"><img src="screenshots/fahrzeuge/fahrzeuge.png" alt="fahrzeuge: fahrzeuge" width="400"></a><br>`fahrzeuge/fahrzeuge` · `/fahrzeuge` |
| <a href="screenshots/fahrzeuge/fristen.png"><img src="screenshots/fahrzeuge/fristen.png" alt="fahrzeuge: fristen" width="400"></a><br>`fahrzeuge/fristen` · `/fahrzeuge/fristen` | <a href="screenshots/fahrtenbuch/fahrzeuge.png"><img src="screenshots/fahrtenbuch/fahrzeuge.png" alt="fahrtenbuch: fahrzeuge" width="400"></a><br>`fahrtenbuch/fahrzeuge` · `/fahrtenbuch` |
| <a href="screenshots/wasserkarte/karte.png"><img src="screenshots/wasserkarte/karte.png" alt="wasserkarte: karte" width="400"></a><br>`wasserkarte/karte` · `/wasserkarte` | <a href="screenshots/objektplaene/objekte.png"><img src="screenshots/objektplaene/objekte.png" alt="objektplaene: objekte" width="400"></a><br>`objektplaene/objekte` · `/objektplaene` |
| <a href="screenshots/personalverwaltung/kameraden.png"><img src="screenshots/personalverwaltung/kameraden.png" alt="personalverwaltung: kameraden" width="400"></a><br>`personalverwaltung/kameraden` · `/personalverwaltung` | <a href="screenshots/schluessel/schluessel.png"><img src="screenshots/schluessel/schluessel.png" alt="schluessel: schluessel" width="400"></a><br>`schluessel/schluessel` · `/schluessel` |
| <a href="screenshots/gefahrenmatrix/matrix.png"><img src="screenshots/gefahrenmatrix/matrix.png" alt="gefahrenmatrix: matrix" width="400"></a><br>`gefahrenmatrix/matrix` · `/gefahrenmatrix` | <a href="screenshots/gefahrstoffe/datenbank.png"><img src="screenshots/gefahrstoffe/datenbank.png" alt="gefahrstoffe: datenbank" width="400"></a><br>`gefahrstoffe/datenbank` · `/gefahrstoffe` |
| <a href="screenshots/anwesenheit/listen.png"><img src="screenshots/anwesenheit/listen.png" alt="anwesenheit: listen" width="400"></a><br>`anwesenheit/listen` · `/anwesenheit` | <a href="screenshots/hydrantenkontrolle/jahreskontrollen.png"><img src="screenshots/hydrantenkontrolle/jahreskontrollen.png" alt="hydrantenkontrolle: jahreskontrollen" width="400"></a><br>`hydrantenkontrolle/jahreskontrollen` · `/hydrantenkontrolle` |
| <a href="screenshots/hydrantenkontrolle/hydranten.png"><img src="screenshots/hydrantenkontrolle/hydranten.png" alt="hydrantenkontrolle: hydranten" width="400"></a><br>`hydrantenkontrolle/hydranten` · `/hydrantenkontrolle/hydranten` | <a href="screenshots/dateien/bereiche.png"><img src="screenshots/dateien/bereiche.png" alt="dateien: bereiche" width="400"></a><br>`dateien/bereiche` · `/dateien` |
| <a href="screenshots/locations/standorte.png"><img src="screenshots/locations/standorte.png" alt="locations: standorte" width="400"></a><br>`locations/standorte` · `/admin/standorte` | <a href="screenshots/vereinsverwaltung/beitritt.png"><img src="screenshots/vereinsverwaltung/beitritt.png" alt="vereinsverwaltung: beitritt" width="400"></a><br>`vereinsverwaltung/beitritt` · `/vereinsbeitritt` |
| <a href="screenshots/vereinsverwaltung/antraege.png"><img src="screenshots/vereinsverwaltung/antraege.png" alt="vereinsverwaltung: antraege" width="400"></a><br>`vereinsverwaltung/antraege` · `/verein` |   |

## Ablauf

1. PostgreSQL 18 (wie in den Tests), PHP 8.4, Composer- und npm-Abhängigkeiten, `npm run build`.
2. `.env` aus dem Profil `tests/e2e/.env.e2e` (Datenbank `openwehr_e2e`, `APP_URL=http://127.0.0.1:8000`, `MAIL_MAILER=log`, Queue `sync`, eingefrorene Uhr `E2E_FROZEN_NOW`).
3. `php artisan migrate:fresh --seed --seeder="Database\\Seeders\\ScreenshotSeeder"` – leere Datenbank, alle Migrationen, Beispieldaten für alle Module.
4. `php artisan serve` auf `127.0.0.1:8000`.
5. Playwright (`tests/e2e`, eigenes `package.json`, nur Chromium): Anmeldung über den echten Magic-Link-Ablauf – E-Mail auf `/anmelden` eingeben, den Link aus der geloggten Mail (`storage/logs/laravel.log`) lesen, bestätigen. Die Sitzung gilt für alle weiteren Seiten; ein erneuter Lauf gegen dieselbe Datenbank verwendet sie weiter, solange sie gültig ist.
6. Pro Seite: HTTP-Status unter 400, keine Umleitung zu `/anmelden`, erwarteter Inhalt sichtbar, dann Screenshot nach `docs-extended/screenshots/<modul>/<seite>.png` (in CI in ein Artefakt).
7. Bei einem manuellen „Docs Screenshots“-Lauf auf `main`: geänderte Bilder werden zurück ins Repository committet (siehe unten).

## Seiten und Beispieldaten

Die Seitenliste steht in `tests/e2e/fixtures/pages.ts` (Modul, Name, Pfad, erwarteter Text, optional ein CSS-Selektor). Weitere Seiten dort ergänzen; fehlen dafür Daten, gehören sie in `database/seeders/ScreenshotSeeder.php`. Eine neue Seite gehört außerdem in die [Galerie](#galerie) und zum Modul in der README; `tests/Unit/Screenshots/DocumentationScreenshotsTest.php` prüft, dass jede Seite ein Bild hat und beide Stellen alle Bilder zeigen.

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
| Erweiterungen | `anwesenheit/listen`, `hydrantenkontrolle/jahreskontrollen`, `hydrantenkontrolle/hydranten`, `dateien/bereiche`, `locations/standorte` (`/admin/standorte`), `vereinsverwaltung/beitritt` (`/vereinsbeitritt`), `vereinsverwaltung/antraege` (`/verein`) |

`ScreenshotSeeder` ruft die Demo-Seeder (Fahrzeuge, Abteilungen, Admin `admin@example.com`, Kameraden, Kleiderkammer) auf, schaltet **alle** registrierten Module und Erweiterungen ein (`ScreenshotSeeder::modules()`, mit automatischer Freischaltung) und legt pro Modul ein paar Datensätze an (Buchungen, Mitteilungen, Termine, Mängel, Einsatzberichte, Hydranten mit laufender Jahreskontrolle, offener Vereinsantrag, zweiter Standort …). Er ist wiederholbar (`firstOrCreate`/`updateOrCreate`). Nur erfundene Personen; nie auf einer echten Datenbank ausführen – `migrate:fresh` löscht alles.

Nicht fotografiert: `/personalakte/nachweise` (der QR-Code hängt vom zufälligen `APP_KEY` ab).

## In GitHub Actions

- **Auslöser:** manuell (**Actions → E2E Screenshots → Run workflow**) und als wiederverwendbarer Workflow (`workflow_call`), z. B. als Release-Gate:

  ```yaml
  jobs:
    e2e:
      uses: ./.github/workflows/screenshots.yml
    release:
      needs: e2e
      # …
  ```

- **Artefakte:** `screenshots` (immer, 30 Tage) mit allen Bildern; bei Fehlern zusätzlich `e2e-report` (Playwright-Report, Traces, Fehlerbilder, `storage/logs/` der Wegwerf-Installation, 7 Tage).
- **Dokumentation aktuell halten:** `docs-screenshots.yml` wird bewusst nur manuell auf `main` gestartet. Er ruft `screenshots.yml` auf, ersetzt `docs-extended/screenshots/` durch das Artefakt und committet nur, wenn sich ein Bild geändert hat („Update documentation screenshots“, als `github-actions[bot]`). Weil der Lauf deterministisch ist, passiert das nur bei geänderter Oberfläche oder geänderten Beispieldaten. Pushes mit `GITHUB_TOKEN` lösen keine weiteren Läufe aus. Ist `main` gegen direkte Pushes geschützt, muss der Bot-Commit regulär per Pull Request übernommen werden oder eine ausdrücklich konfigurierte Ausnahme nutzen – sonst schlägt dieser Schritt fehl. Die vorhandenen Bilder sind übernommen und zeigen gegebenenfalls noch den früheren Produktnamen.
- Die Bilder und die Erweiterung `extensions/E2e` kommen nicht ins Docker-Image (`.dockerignore`).
- `screenshots.yml` selbst braucht nur Leserechte und ist damit als Release-Gate (`workflow_call`) nutzbar. Keine Secrets nötig.

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

Variablen: `E2E_BASE_URL` (Standard `http://127.0.0.1:8000`), `E2E_EMAIL` (Standard `admin@example.com`), `E2E_MAIL_LOG` (Pfad zum Mail-Log relativ zu `tests/e2e`, Standard `../../storage/logs/laravel.log`), `E2E_SCREENSHOT_DIR` (relativ zu `tests/e2e` oder absolut, Standard `../../docs-extended/screenshots` – ein lokaler Lauf aktualisiert also direkt die Bilder der Doku). Der Magic Link ist auf 5 Anforderungen pro Stunde und Adresse begrenzt, und bei eingefrorener Uhr läuft diese Stunde nie ab. Wiederholte Läufe verwenden deshalb die gespeicherte Sitzung (`tests/e2e/.auth/admin.json`); nach `migrate:fresh` wird neu angemeldet. Für byte-gleiche Bilder wie in CI vor dem Lauf `migrate:fresh` ausführen (jede Anmeldung erscheint im Admin-Dashboard unter „Letzte Anmeldungen“).

## Stabile Bilder

Zwei Läufe auf frisch aufgesetzten Installationen liefern byte-gleiche PNGs (gleicher Runner/Browser). Dafür:

- **Eingefrorene Uhr:** `E2E_FROZEN_NOW` (ISO 8601 mit Offset, z. B. `2026-10-14T10:00:00+02:00`). Der Server hält die Zeit über die Erweiterung `extensions/E2e` an (`Carbon::setTestNow`, nur wenn gesetzt, nie in `production` und nie in Pest); der Browser bekommt dieselbe Zeit (`page.clock.setFixedTime`, Wert aus der Umgebung oder der `.env`). Beispieldaten, „vor 2 Tagen“, Kalenderwoche und Fristen sind so in jedem Lauf gleich. Leer lassen = echte Uhr.
- Weil Cookies vom eingefrorenen Datum aus ablaufen, ist `SESSION_LIFETIME` im Profil sehr lang.
- Feste Fenstergröße 1440×900, Skalierung 1, `de-DE`, Zeitzone `Europe/Berlin`, helles Farbschema, reduzierte Bewegung, ein Worker; Chromium mit Software-Rendering ohne Partial Raster (gleiche Kantenglättung).
- Nur Anfragen an die App selbst; externe (Kartenkacheln der Wasserkarte u. Ä.) werden abgebrochen, damit das Netz die Bilder nicht verändert.
- Vor dem Bild: sichtbarer Zielinhalt, Netzwerk ruhig (`networkidle`), Schriften und Bilder geladen, zwei Animation-Frames; Animationen, Übergänge und Cursor sind aus. Keine festen Wartezeiten.
- Das schwebende Offline-Statusfeld (`extensions/Offline`) wird nur im Bild ausgeblendet.
