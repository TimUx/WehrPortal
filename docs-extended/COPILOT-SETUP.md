# Copilot-Entwicklungsumgebung

Die Fork-Datei `.github/workflows/copilot-setup-steps.yml` stellt PHP 8.4 mit
`pdo_pgsql`, `intl`, `gd`, `exif` und den Composer-Lockfile-Erweiterungen bereit
(`composer.json` fordert selbst keine `ext-*`). Sie installiert Composer-v2-
Abhängigkeiten einschließlich Pest, Larastan und Pint, erzeugt `.env` und einen
lokalen Anwendungsschlüssel und migriert PostgreSQL 18 ohne Beispieldaten.
`route:list` prüft den Anwendungsstart. Nur Leserechte, keine Secrets.

PostgreSQL läuft auf `127.0.0.1:5432` mit Datenbank `openwehr_test`, Benutzer
`openwehr`, lokalem Testpasswort `secret` und Healthcheck. Die Migration aktiviert
`btree_gist` auch in den parallelen Testdatenbanken; der lokale DB-Benutzer darf
sie und weitere Testdatenbanken anlegen. Gegenüber `.env.example` werden Datenbank
und Passwort angepasst, Mail auf `log` und Queue auf `sync` gesetzt.

**Kein Node-Setup, npm-Cache, `npm ci` oder Build:** `tests/TestCase.php` schaltet
Vite mit `withoutVite()` ab. Die vier PHP-Prüfungen benötigen kein Vite-Manifest.
Für interaktive UI-Arbeit separat `npm ci && npm run build` ausführen.

## Lokal reproduzieren

Im Dev-Container (`app` mit PHP 8.4, `db` mit PostgreSQL 18) richtet
`.devcontainer/post-create.sh` die Umgebung ein. Danach im Repository:

```bash
./vendor/bin/pest --parallel
./vendor/bin/phpstan analyse --memory-limit=2G
./vendor/bin/pint --test
php artisan route:list
```

Ohne Dev-Container, nach `CLAUDE.md` (Docker, im Repository-Verzeichnis):

```bash
docker run -d --name openwehr-test-pg -e POSTGRES_DB=openwehr_test -e POSTGRES_USER=openwehr -e POSTGRES_PASSWORD=secret -p 127.0.0.1:5432:5432 postgres:18
cp .env.example .env
sed -i -e 's/^DB_DATABASE=.*/DB_DATABASE=openwehr_test/' -e 's/^DB_PASSWORD=.*/DB_PASSWORD=secret/' -e 's/^MAIL_MAILER=.*/MAIL_MAILER=log/' .env
docker run --rm --network host -v "$PWD":/app -w /app --user root -e SHOW_WELCOME_MESSAGE=false serversideup/php:8.4-cli \
  sh -c 'install-php-extensions gd intl exif &&
    composer install --no-interaction --prefer-dist &&
    php artisan key:generate && php artisan migrate --force &&
    ./vendor/bin/pest --parallel;
    ./vendor/bin/phpstan analyse --memory-limit=2G;
    ./vendor/bin/pint --test; php artisan route:list;
    chown -R 1000:1000 storage bootstrap/cache'
docker rm -f openwehr-test-pg
```

Die Einzelbefehle und ihre Exitcodes separat bewerten; der Docker-Sammelbefehl
endet mit `chown` (verhindert root-eigene Build-Dateien), nicht mit dem Testergebnis.

## Aktivierung und Grenzen

Copilot lädt die Datei nur aus dem Standard-Branch. Dieser ist derzeit `main`;
ein Merge nach `extended` allein aktiviert sie nicht: zusätzlich nach `main`
übernehmen. Danach unter Actions → **Copilot Setup Steps** → **Run workflow**
manuell prüfen; PRs prüfen sie nur bei Änderungen an der Workflow-Datei.
Kein Playwright/Browser, SMTP-Dienst, Produktionszugang oder Deployment.
Bestehende Test-, Analyse- oder Stilbefunde werden nicht automatisch repariert.
Bei Download-Blockaden: Repo-Einstellungen → Copilot → Coding agent → Allowlist;
Composer benötigt insbesondere `repo.packagist.org`, `github.com`,
`api.github.com`, `codeload.github.com`, optionales npm `registry.npmjs.org`.
In der Einrichtungssitzung lieferte `api.github.com` HTTP 403
(`Blocked by DNS monitoring proxy`); Composer brach mit
`Could not authenticate against github.com` ab. Ohne installierte Abhängigkeiten
endeten Pest, PHPStan und Pint mit `not found` (127), `route:list` wegen fehlender
`vendor/autoload.php` mit 255. Nach der Freigabe alle vier Prüfungen wiederholen.
