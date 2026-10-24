# WehrPortal: Herkunft, Betrieb und Migration

WehrPortal wird als eigenständiges öffentliches Projekt unter
[TimUx/WehrPortal](https://github.com/TimUx/WehrPortal) entwickelt. Der Standardbranch
ist `main`. Ausgangspunkt ist ausschließlich der Branch `extended` von
`TimUx/openwehr-extended`, Stand `3e280daf6e7a2ef9222d3240779c7e3837f368aa`.
Die erreichbare Git-Historie einschließlich der ursprünglichen Urheber bleibt
erhalten. Andere Quellbranches und Tags werden nicht automatisch übertragen.

## Herkunft und Lizenz

WehrPortal ist auf der Idee und Codebasis von **openwehr** von Robert Einsle und weiteren Beitragenden
([ursprüngliches Projekt](https://github.com/roberteinsle/openwehr)) sowie den
Erweiterungen aus `TimUx/openwehr-extended` entstanden, für eigene Zwecke angepasst
und wird eigenständig weiterentwickelt. Code und die historischen
Illustrationen bleiben unter der **GNU AGPL-3.0-only**; die vollständige
Lizenz steht in [LICENSE](LICENSE). Frühere Copyright- und Urheberhinweise bleiben
gültig. Die Umbenennung beansprucht keine alleinige Urheberschaft.

Die aktuelle App-Marke (rotes Portal mit Flamme) ist ein eigenständiger Entwurf
für WehrPortal, keine Übernahme eines fremden Logos oder Feuerwehrwappens.
Quellen: `public/images/wehrportal-logo.svg` und `wehrportal-icon.svg`; die
Icon-Varianten erzeugt `bash tools/build-branding.sh` mit ImageMagick. Die neue
Marke steht ebenfalls unter AGPL-3.0-only. Feuerwehrlogo und Standortwappen
sind davon unabhängige, durch berechtigte Admins pflegbare Instanzdaten.

Diese Herkunft ist keine technische Abhängigkeit: Installation, Builds, Updates
und Releases beziehen sich ausschließlich auf WehrPortal. Es gibt keinen
Upstream-Sync, keine automatischen Merges und keine Abhängigkeit von einem
Checkout, Release oder Container-Image der bisherigen Repositories. Laravel und
andere externe Bibliotheken sowie optionale Fachdienste bleiben reguläre
Abhängigkeiten.

[FORK.md](FORK.md) und datierte Audit-/Planungsdokumente sind historische
Aufzeichnungen, keine aktuellen Update-Anweisungen. Alte Screenshots zeigen
gegebenenfalls noch den früheren Produktnamen.

## Bestehende Installationen übernehmen

Die Repository-Migration führt **keinen produktiven Rollout** aus. Zuerst auf einer
getrennten Testinstanz mit einer konsistenten Sicherung erproben:

1. Datenbank, `.env`/Betriebskonfiguration und sämtliche persistenten Dateien
   gemeinsam sichern; einen Restore prüfen. Insbesondere `storage/app` mit den
   privaten Dateien und den **bestehenden `APP_KEY`** erhalten. Einen neuen
   Anwendungsschlüssel zu erzeugen würde verschlüsselte Bestandsdaten unlesbar machen.
2. Checkout aus `https://github.com/TimUx/WehrPortal.git`, Branch `main`, verwenden
   oder ein **tatsächlich veröffentlichtes** Image
   `ghcr.io/timux/wehrportal:<fester-Tag>` beziehen. Die Repository-Erstellung
   allein veröffentlicht noch kein Image. Bis zur ersten Veröffentlichung ist
   ein lokaler Build (`docker compose build`) erforderlich.
3. Bestehende Datenbank, Datenbankbenutzer, Volumes, Dateien, `APP_URL`, Schlüssel,
   VAPID-/OIDC-Einstellungen und Secrets weiterverwenden. Nicht `migrate:fresh`,
   Seeder oder `composer setup` auf einer Bestandsdatenbank ausführen.
   Regulär aktualisieren: Abhängigkeiten/Assets bauen und
   `php artisan migrate --force`; im Produktionsimage geschieht die Migration
   beim Start. Es werden durch die Umbenennung keine Tabellen umbenannt.
4. `APP_NAME` und `MAIL_FROM_NAME` dürfen auf `WehrPortal` geändert werden;
   individuelle Wehr-Namen und gespeicherte Organisations-/Mailtexte bleiben
   erhalten. Vor dem Ändern von `APP_NAME` die bisher daraus abgeleiteten Werte
   **explizit festschreiben**: `SESSION_COOKIE`, `CACHE_PREFIX` und `REDIS_PREFIX`.
   Bei bisherigem `APP_NAME=openwehr` und ohne eigene Overrides sind das
   `openwehr-session`, `openwehr-cache-` und `openwehr-database-`. Bei anderen
   Namen die tatsächlichen bisherigen Werte verwenden. Andernfalls werden
   Sitzungen ungültig und Cache-/Redis-Schlüssel wechseln den Namensraum.
5. Konfigurationscache erneuern und Anwendung neu starten. Anmeldung,
   Berechtigungen, Entschlüsselung, Dateidownloads, E-Mail, Push, Kalender-Abos
   und Offline-Entwürfe prüfen. Rollback nur mit passendem Daten-/Dateibackup.

Der Compose-Projektname `openwehr` bleibt absichtlich erhalten, damit
`openwehr_pgdata`, `openwehr_app_storage` und weitere bestehende Volumes nicht
durch leere neue Volumes ersetzt werden. Auch der Projektname einer bestehenden
Compose-Installation außerhalb dieser Datei muss unverändert bleiben
(`docker compose -p <bisheriger-Name> …`). Datenbank-/Testbenutzer, die öffentliche
Logo-Datei `images/openwehr-logo.svg` (Alias der neuen Marke), externe Melde-Cookies,
Offline-/IndexedDB-Namen, Cache-Schlüssel und der UID-Fallback bestehender
Kalenderfeeds bleiben aus Kompatibilitätsgründen erhalten. Diese lokalen
Bezeichner stellen keine Verbindung zu einem anderen Repository her.

Auch `X-Openwehr-Resend-Of` bleibt als interner Mail-Header für die
Wiederholungszuordnung erhalten. OIDC-Testfixtures mit `openwehr` prüfen die
Weiterverwendung bestehender IdP-Clientkennungen. Historische Auditbelege und
Screenshots bleiben als solche erkennbar; sie werden nicht nachträglich als
WehrPortal-Prüfergebnisse ausgegeben. Die Datenbank- und Testbenutzernamen
werden konsistent beibehalten, nicht nur in Compose, sondern auch in
Devcontainer, Testprofilen und CI.

Die additive Standortwappen-Migration ergänzt nur eine optionale Bildspalte.
Bestehende Namen und Feuerwehrlogos bleiben erhalten. Neue Bilduploads sind
auf PNG/JPEG/WebP begrenzt und werden neu kodiert; bestehende SVG-Logos bleiben
sandboxed abrufbar, bis ein Admin sie ersetzt oder entfernt. Der Service Worker
erneuert nur gecachte App-Bilder, nicht Offline-Entwürfe oder Referenzdaten.

Bei einem Domainwechsel funktionieren bestehende signierte Links, Push-Abos,
OIDC-Rückrufe und browserlokale Entwürfe nicht automatisch weiter. Deshalb ist
ein Domainwechsel **nicht** Bestandteil dieser Migration.

## Eigenständige Entwicklung und Veröffentlichungen

- Core unter `app/`, Fachmodule unter `modules/`, zusätzliche Module unter
  `extensions/`; vorhandene Namespaces, Registries, Routen und Migrationen bleiben
  erhalten. Änderungen müssen nicht mehr gegenüber einem Upstream isoliert werden.
- `.github/workflows/deploy.yml` führt Tests und Sicherheitsprüfungen für `main`
  aus. Container-Publikation ist standardmäßig aus und benötigt die Repository-
  Variable **`PUBLISH_IMAGES=true`**. Es gibt **keinen Deployment-Webhook**.
- `.github/workflows/release.yml` verwendet dieselben CI-Prüfungen und verwendet unabhängige Tags mit zwei bis vier
  Versionsbestandteilen, z. B. `v0.9.5.5`, passend zu `V.0.9.5.5` in
  `config/app.php`. Auch dieser Workflow benötigt `PUBLISH_IMAGES=true`.
  Release-Images werden ebenfalls vor dem Push gescannt. Tags und Images aus den früheren Repositories werden nicht übernommen.
- `.github/workflows/security.yml` prüft wöchentlich Abhängigkeiten; der
  Image-Scan läuft erst nach Aktivierung der Image-Publikation.
- Dokumentationsbilder werden bewusst **manuell** über „Docs Screenshots“ auf
  `main` erneuert. Ein Import erzeugt dadurch keinen zusätzlichen Bot-Commit.
- Neue Installationen verwenden WehrPortal als Vorgabenamen; individuelle
  Organisationsnamen bleiben konfigurierbar.

Vor der ersten Image-Veröffentlichung GHCR-Zugriff und Paketsichtbarkeit prüfen.
GitHub-Einstellungen werden nicht durch Git übertragen: Actions-Berechtigungen,
Branch-Schutz, Dependabot-Sicherheitsupdates, private Sicherheitsmeldungen und Benachrichtigungen
im neuen Repository separat konfigurieren. Optionale Secrets sind
`DOCKERHUB_USERNAME` und `DOCKERHUB_TOKEN`. Der optionale externe KI-Review wurde
entfernt; es ist kein `ANTHROPIC_API_KEY` mehr erforderlich. Deployment-Secrets des alten Repositories werden nicht
kopiert. Secret-Werte gehören weder in Git noch in Migrationsberichte.

`docs/` ist eine statische Projektseiten-Vorlage, keine übernommene Website:
Die alte Domainbindung und fremde Betreiber-/Datenschutzerklärungen werden nicht
weitergenutzt. GitHub Pages nicht automatisch aktivieren. Vor einer eigenen
Veröffentlichung rechtlich passende Betreiber- und Datenschutzhinweise ergänzen.

## Altes Repository erst nach gesonderter Freigabe löschen

Ein erfolgreicher Git-Push sichert **nicht** alle GitHub-Daten. Vor einer Löschung
müssen auch geschlossene Issues/PRs samt Anhängen und Diskussionen, Releases samt
Assets, Wiki, Packages, Deployments/Umgebungen, Actions-Artefakte und
Repository-Einstellungen geprüft und bei Bedarf gesondert gesichert werden.
Secrets lassen sich nicht auslesen; benötigte Werte aus dem eigenen
Secret-Management neu hinterlegen. Laufende Installationen können weiterhin
alte Images oder Quellcode-URLs benötigen.

Die Löschung von `TimUx/openwehr-extended` ist ein separater, destruktiver Schritt
und wird durch diese Migration **nicht** ausgeführt oder automatisch freigegeben.
