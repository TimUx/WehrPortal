# Fork-Leitfaden (openwehr-extended)

[Zur Hauptdokumentation](README.md) · [Erweiterungsindex](docs-extended/README.md)

Dieser Fork erweitert [roberteinsle/openwehr](https://github.com/roberteinsle/openwehr) und soll dem Original so nah wie möglich bleiben, damit Updates konfliktarm übernommen werden können.

## Inhalt

- [Grundregeln](#grundregeln)
- [Architektur](#architektur)
- [Upstream-Updates übernehmen](#upstream-updates-übernehmen)
- [Fork-spezifische Punkte](#fork-spezifische-punkte)
- [Liste der Upstream-Eingriffe](#liste-der-upstream-eingriffe)

## Grundregeln

1. **Upstream-Dateien nicht ändern**, sondern erweitern. Eigener Code liegt in neuen Dateien/Ordnern, die es im Original nicht gibt – Git-Merges konfliktieren dann nicht.
2. Muss eine Upstream-Datei doch angepasst werden: klein halten, in einem eigenen Commit mit Präfix `fork:`, und hier unter „Liste der Upstream-Eingriffe“ dokumentieren.
3. Fehlt im Core ein Einhängepunkt, besser als Pull Request an das Original beitragen (oder dort ein Issue eröffnen) und bis dahin minimal lokal lösen.

## Architektur

| Zweck | Ort | Hinweis |
|---|---|---|
| Neue Module | `extensions/<Name>/` | Namespace `Extensions\<Name>\`, Aufbau wie ein Original-Modul (`src/`, `routes/`, `database/migrations`, `resources/views`) |
| Provider | `extensions/<Name>/<Name>ServiceProvider.php` | wird von `Extensions\ExtensionsServiceProvider` automatisch gefunden; `bootstrap/providers.php` bleibt unverändert |
| Core-Erweiterungen | `extensions/Core/` (Provider `CoreServiceProvider`) | Eigene Services, Events/Listener, Policies, Filament-Seiten (über Plugin am Panel) |
| Erweiterung bestehender Module | Registries (`ModuleRegistry`, `DashboardRegistry`, `PersonnelFileRegistry`, `HoursRegistry` …) und Blade-Partials | Im `boot()` des eigenen Providers registrieren statt Modulklassen zu ändern |
| Migrationen | `extensions/<Name>/database/migrations` mit eigenem Tabellenpräfix (z. B. `x_`) | Nie Upstream-Migrationen ändern; Spalten an Upstream-Tabellen per neuer Migration (`ext_`-Spaltenpräfix) ergänzen |
| Tests | `tests/Feature/Extensions/…` | eigener Unterordner, keine Kollision mit Upstream-Tests |
| Konfiguration | `config/extensions.php` bzw. `ext_*`-Settings | statt `config/app.php` zu ändern |
| Übersteuern von Klassen | Container-Bindings (`$this->app->extend/bind`), Model-Observer, Events | statt Quelltext zu patchen |

Beispiel-Provider (`extensions/Core/CoreServiceProvider.php`):

```php
namespace Extensions\Core;

use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'ext-core');
    }
}
```

Einmalige Änderungen an Upstream-Dateien für diese Architektur (sehr klein):

- `composer.json` / `composer.lock`: PSR-4 `Extensions\` → `extensions/`. `extra.laravel.providers` allein reicht nicht (Laravel liest Provider des Root-Pakets nicht), deshalb steht `Extensions\ExtensionsServiceProvider` zusätzlich in `bootstrap/providers.php` (eine Zeile).

## Upstream-Updates übernehmen

Der Workflow `.github/workflows/upstream-sync.yml` (wöchentlich, manuell startbar) mergt `roberteinsle/openwehr@main` in den Branch `upstream-sync` und öffnet einen Pull Request. Bei Konflikten ist der PR ein Draft mit Konfliktmarkern.

Ablauf:
1. PR prüfen, CI (Tests, Larastan, Audits) muss grün sein.
2. Konflikte lokal lösen: `git fetch origin && git checkout upstream-sync`, Konflikte beheben, pushen.
3. **Merge-Commit verwenden** (kein Squash, kein Rebase), damit Git die gemeinsame Historie kennt und künftige Syncs nur Neues betreffen.
4. Neue Upstream-Migrationen laufen automatisch; bei Namens-/Tabellenkonflikten mit Erweiterungen den Präfix der eigenen Tabellen prüfen.

Lokal geht es genauso: `git remote add upstream https://github.com/roberteinsle/openwehr.git && git fetch upstream && git merge upstream/main`.

Empfehlung: Branch Protection auf `main` (PR + grüne Checks), damit Upstream-Merges nie ungetestet landen. Releases von Upstream (Tags) kann man über `git fetch upstream --tags` verfolgen.

## Fork-spezifische Punkte

- **Offline/PWA:** Die additive Erweiterung liegt unter `extensions/Offline/`; Details und Grenzen stehen in [`docs-extended/OFFLINE.md`](docs-extended/OFFLINE.md). Siehe dort auch die erforderlichen kleinen Eingriffe in Layout, Wasserkarte-Formular und Service-Worker-Einbindung.
- **Single Sign-On (OIDC):** Die Erweiterung liegt unter `extensions/Oidc/`; Einrichtung, Keycloak, lokale Admins und Notfall-Wiederherstellung stehen in [`docs-extended/OIDC.md`](docs-extended/OIDC.md). Der Core-Hook (`AuthMethodRegistry`) ist generisch und kann als Pull Request an das Original vorgeschlagen werden (eigener Commit-Kandidat: `app/Services/AuthMethod*.php`, `User::isLocalAdmin()`, Login-Seite, `MagicLinkService`).
- **Mehrere Standorte:** Die Erweiterung liegt unter `extensions/Locations/`; notwendige kleine Core-Adapter sind unten aufgeführt. Rollen, Scope und Grenzen stehen in [`docs-extended/LOCATIONS.md`](docs-extended/LOCATIONS.md). Zusätzlich `docker/entrypoint.d/61-ensure-locations.sh` (neue Datei).
- **Anwesenheitslisten:** Eigenständiges Modul unter `extensions/Anwesenheit/` (kein Upstream-Eingriff); Funktionsumfang, Standort-Regeln und Offline-Entwürfe in [`docs-extended/ANWESENHEIT.md`](docs-extended/ANWESENHEIT.md).
- **Dateien:** Interne Dateiablage unter `extensions/Dateien/` (kein Upstream-Eingriff); Dateigruppen, vererbte ACLs, private Versionen und Papierkorb in [`docs-extended/DATEIEN.md`](docs-extended/DATEIEN.md). Online-Office (ONLYOFFICE/Collabora) und optionale öffentliche Links. Öffentliche Links weichen bewusst von „keine öffentlichen Datei-URLs“ in `SECURITY.md` (Upstream, unverändert) ab: standardmäßig aus, nur mit Admin-Schalter **und** Freigabe pro Ablage, Token nur gehasht, Pflicht-Ablauf, nur lesend, nur der freigegebene Teilbaum, sonst immer 404.
- **Einsatzbericht-Ergänzungen:** `extensions/EinsatzberichtPlus/` ergänzt Kostenpflicht, beteiligte Personen, Verdienstausfall, externes Fahrzeug und Sollbesatzung-Hinweise (eigene `x_ebp_*`-Tabellen). Analyse und Grenzen in [`docs-extended/EINSATZBERICHT.md`](docs-extended/EINSATZBERICHT.md); ein Upstream-Eingriff (siehe Tabelle).
- **Mitteilungen:** `extensions/Mitteilungen/` ergänzt optionale ntfy-/Matrix-Ziele und Versandprotokolle. Das bestehende Modul erhält die explizite Standortzuordnung und erzwingt sie in Empfänger-, Bearbeitungs- und Anhangspfaden. Für den optionalen Standortrollenadapter bindet Locations den eigenen `ScopedAnnouncementService` an den bestehenden Dienst; zielbezogene Rollenlogik bleibt in der Extension. Details und Datenschutzgrenzen in [`docs-extended/MITTEILUNGEN.md`](docs-extended/MITTEILUNGEN.md).
- **Workflows:** `fork-deploy.yml`, `fork-release.yml`, `fork-security.yml` ersetzen die Originale. Image: `ghcr.io/<owner>/<repo>` (automatisch aus `github.repository`). Die Original-Workflows `deploy.yml`, `release.yml`, `security.yml` sind deaktiviert (nur `workflow_dispatch`, nur der `on:`-Block geändert – bei Merge-Konflikten die eigene Variante behalten). Tests und Security-Checks laufen weiter bei Push/PR über `fork-deploy.yml`.
- **Copilot-Umgebung:** `.github/workflows/copilot-setup-steps.yml` ist eine neue Fork-Datei (kein Upstream-Konflikt) für PHP 8.4, Composer und PostgreSQL 18; Reproduktion und Grenzen stehen in [`docs-extended/COPILOT-SETUP.md`](docs-extended/COPILOT-SETUP.md).
- **E2E-Screenshots:** `screenshots.yml` (manuell, Push auf `extended`, `workflow_call` als Release-Gate) setzt eine Testinstallation mit `Database\Seeders\ScreenshotSeeder` auf, meldet sich per Magic Link an und lädt Screenshots als Artefakt hoch. `docs-screenshots.yml` committet geänderte Bilder nach `docs-extended/screenshots/`; README und `docs-extended` binden sie ein. Playwright liegt mit eigenem `package.json` unter `tests/e2e/` (kein Eingriff in das Upstream-`package.json`); `extensions/E2e/` hält bei gesetztem `E2E_FROZEN_NOW` die Uhr an (nie in `production`), damit die Bilder stabil sind; Details in [`docs-extended/SCREENSHOTS.md`](docs-extended/SCREENSHOTS.md).
- **PR-Prüfungen für `extended`:** `fork-deploy.yml` prüft Pull Requests gegen `main` und `extended`. Dateien-Tests laufen vor der vollständigen Regression; Blade-Kompilierung sowie Dateien-Pint und die Extension-PHPStan-Konfiguration sind eigene Prüfschritte. Image-Build und Deployment bleiben auf Pushes nach `main` begrenzt.
- **Secrets/Variablen (optional):** `DOCKERHUB_USERNAME`/`DOCKERHUB_TOKEN` (Pull-Limit), `COOLIFY_DEPLOY_ENABLED` (Variable) + `COOLIFY_DEPLOY_WEBHOOK`/`COOLIFY_WEBHOOK_TOKEN`. Ohne sie wird das Deployment übersprungen.
- **Release-Tags:** Format `v<upstream-version>-x<n>`; `config/app.php` → `version` muss `V.<upstream-version>-x<n>` lauten, sonst bricht `fork-release.yml` ab.
- **Version:** `config/app.php` → `version` bei Releases des Forks mit Suffix `-x<n>` führen, damit Upstream- und Fork-Stand unterscheidbar sind.
- **Lizenz:** AGPL-3.0 gilt weiter; Änderungen bleiben unter derselben Lizenz und der Quellcode muss Nutzern zugänglich sein.
- **Öffentliche Daten:** Die Regeln aus `CLAUDE.md` (keine echten Namen/Domains in Code, Tests, Docs) gelten auch hier.

## Liste der Upstream-Eingriffe

| Datei | Grund |
|---|---|
| `tests/Feature/Gefahrstoffe/MaterialImportTest.php` | Cache-Control-Erwartung an die normalisierte Reihenfolge `no-store, private` angepasst; beide Schutzdirektiven bleiben geprüft |
| `modules/Gefahrstoffe/src/Services/MaterialImport.php` | Validierungsfehler mit `messages()` feldweise behalten: Duplikatprüfung und anschließendes Zusammenführen erwarten Listen pro Feld, nicht die flache Liste aus `all()`; behebt zugleich den PHPStan-Fehler „Cannot assign new offset to string“ |
| `composer.json`, `composer.lock` | Autoload und Provider-Discovery für `extensions/` |
| `bootstrap/providers.php` | `Extensions\ExtensionsServiceProvider` (Root-`extra.laravel.providers` wird von Laravel nicht entdeckt) – ohne diese Zeile lädt keine Erweiterung |
| `app/Models/User.php` | OIDC: `isLocalAdmin()` (+ `use`); Locations: kleiner Adapter für vorbereitete Standort-Modulzugänge und explizite Zielstandort-Rollenprüfung, nur für ausdrücklich aktivierte Module; `hasAnyModuleRole()` ausschließlich für Navigation. Der optionale persönliche Kleiderkammeradapter verwendet seinen eigenen Moduleinstieg; dieser ist keine Objektfreigabe. Ohne Aktivierung bleiben Legacy-Regeln erhalten. |
| `app/Models/User.php`, `modules/Mitteilungen/src/Services/AnnouncementService.php`, `modules/Mitteilungen/src/Models/Announcement.php`, `modules/Mitteilungen/src/Livewire/Manage.php`, `modules/Mitteilungen/routes/web.php`, `modules/Mitteilungen/resources/views/livewire/manage.blade.php` | P2b Mitteilungen: Moduleinstieg von Objektbefugnis trennen; überschreibbare Scope-/Admin-/Abfrage- und Leserprüfungen, Hook für aktuelle Empfängerstandorte, autorisierte Standortauswahl und explizite Akteursweitergabe an Statistik, Erinnerung und Versand. Der optionale `ScopedAnnouncementService` prüft aktuelle Standortvergaben, NULL-Verwaltung und ausdrücklich angegebene Versandempfänger; die Standardimplementierung erhält das Legacy-Rollenmodell. |
| `app/Filament/Resources/UserResource.php` | Locations: Hinweise an der bisherigen Modulrollenvergabe unterscheiden Legacy-Zugänge von ausdrücklich aktivierten Standortrollen; alte Formulare erzeugen keine impliziten Standortvergaben. |
| `app/Services/MemberRemoval.php` | Locations: unveränderliche Standort-Modulrollen- und Bestandszuordnungshistorie wird niemals auf eine andere Person übertragen; bei geschützten Verweisen wird die Löschung verständlich abgewiesen. |
| `modules/Kleiderkammer/src/Services/IssuingService.php` | Mengenbestandsherkunft: neue Mengenausgaben speichern den tatsächlich belasteten Bestand; Rücknahmen mit Herkunft füllen genau diesen auf, auch bei abgeschalteter Locations-Erweiterung. Optionaler `StockAccess`-Adapter prüft persönliche Verwaltungsrechte und Bestandsziel bei Mengenausgabe/Rücknahme; `takeBack` läuft atomar. Ohne aktivierten Adapter bleiben Legacy-Berechtigungen erhalten, historische NULL-Herkünfte werden nicht geraten. |
| `modules/Kleiderkammer/src/Services/StockAccess.php` | Kleiner neutraler Einhängepunkt für ausdrücklich aktivierte, zielbezogene Mengenbestandsrechte; Standardimplementierung bewahrt das bisherige Verhalten. Die Locations-Implementierung bleibt Extension-eigen. |
| `modules/Kleiderkammer/src/Services/PersonalAccess.php` | Neutraler Einhängepunkt für separat aktivierte personenbezogene Kleiderkammerrechte. Die Standort-/Mitgliedschafts- und Herkunftsprüfung liegt in der Locations-Erweiterung; `bestand` wird dadurch nicht zu einer persönlichen Verwaltungsrolle. |
| `modules/Kleiderkammer/src/Filament/Concerns/KleiderkammerArea.php`, `MemberResource.php`, `MemberResource/Pages/EditMember.php`, `MemberResource/RelationManagers/SizesRelationManager.php`, `ItemResource.php` | Persönliche Verwaltungsseiten, Mitgliederabfragen, Größen und Einzelteile prüfen den optionalen persönlichen Adapter auch am Zielobjekt; Formulare allein gelten nicht als Autorisierung. |
| `modules/Kleiderkammer/KleiderkammerServiceProvider.php` | Der vorhandene Scan-/Suchpfad verwendet die optionalen persönlichen Objektgrenzen; Moduleinstieg und Navigation bleiben von der Datensatzfreigabe getrennt. |
| `modules/Kleiderkammer/src/Services/EquipmentMessageService.php`, `Filament/Resources/EquipmentMessageResource.php`, `Models/EquipmentMessage.php`, `Models/IssuedItem.php`, `Notifications/EquipmentMessageNotification.php`, `Notifications/WashingRequestedNotification.php` | Personenbezogene Meldungen und ihr Ausgabebezug werden durch den persönlichen Adapter geprüft. Empfänger und Mailinhalte dürfen nur tatsächlich freigegebene Ausgaben enthalten; Eigentümerbezüge werden nicht aus heutigen Mitgliedschaften nachgetragen. Die Wäscheaktion und der Dienst verwenden dieselbe Freigabe; Abschlüsse mit personenbezogenem Behandlungseintrag bleiben bei aktivierter Personendurchsetzung globalen Admins vorbehalten. |
| `modules/Kleiderkammer/src/Services/LabelSheetGenerator.php`, `Filament/Pages/DataTransferPage.php` | Sammelausgaben und pauschale Übertragung berücksichtigen den optionalen persönlichen Adapter statt aus dem neuen Moduleinstieg globale Datenrechte abzuleiten. |
| `modules/Kleiderkammer/src/Services/CodeResolver.php`, `BarcodeRenderer.php`, `QrCodeRenderer.php` | Scan- und Etikettenauflösung beachten den optionalen persönlichen Adapter; bei abgeschaltetem Adapter bleiben die bisherigen Modell-/Standortfilter erhalten, statt sie pauschal zu umgehen. |
| `app/Filament/ModuleAreas.php` | Berücksichtigt auch ausdrücklich als `ModuleArea` deklarierte Ressourcenseiten; so dient die vorhandene Mengenbestandsliste als begrenzter Panel-Einstieg, ohne ein persönliches Modul-Dashboard freizuschalten. |
| `modules/Kleiderkammer/src/Filament/Resources/StockResource.php`, `StockResource/Pages/EditStock.php`, `StockResource/Pages/ListStock.php` | Mengenbestandslisten und Fachaktionen verwenden den optionalen Bestandsadapter; die bestehende Liste ist zugleich der begrenzte Panel-Einstieg. Reine Bestandsrechte schalten keine Personenverwaltung frei; nichtglobale Bestandsberechtigte ändern ausschließlich Stückzahlen. |
| `modules/Kleiderkammer/src/Filament/Resources/MemberResource/RelationManagers/IssuancesRelationManager.php` | Mengenauswahl und Bestandszahlen berücksichtigen den optionalen Bestandsadapter; der Ausgabedienst prüft das gewählte Ziel zusätzlich serverseitig. |
| `modules/Kleiderkammer/src/Services/DataTransferService.php` | Pauschaler Import/Export bleibt bei aktivierter Bestands- oder Personendurchsetzung globalen Admins vorbehalten, statt Standortregeln über einen Nebenpfad zu umgehen. |
| `modules/Kleiderkammer/src/Services/InventoryReport.php` | Der Mengenbestandsabschnitt verwendet bei aktivierter Durchsetzung dieselbe zielbezogene Bestandsabfrage wie die Verwaltung. Bei aktivierter Personendurchsetzung bleibt der Gesamtbericht globalen Admins vorbehalten. |
| `app/Services/MagicLinkService.php` | OIDC: Magic-Link nur für erlaubte Benutzer (`AuthMethodRegistry::allowsMagicLink`) beim Anfordern und Einlösen; `@var`-Hinweis für PHPStan |
| `resources/views/auth/login.blade.php` | OIDC: Liste der Login-Methoden, Fehlermeldung `auth`, Magic-Link-Formular bei „nur IdP“ ausblenden |
| `phpstan-baseline.neon` | 6 Einträge zu `MagicLinkService` entfernt (durch den `@var`-Hinweis behoben); vier durch die neue Ausgabelogik gegenstandslos gewordene Einträge zu `IssuingService` entfernt. Keine neuen Befunde baselined. |
| `tests/Feature/Extensions/OfflineTest.php`, `tests/Feature/Notifications/PushTest.php` | erwarteten `Cache-Control`-Header des `/sw.js` an Laravels normalisierte Form angepasst (Tests schlugen fehl, sobald die Erweiterungen tatsächlich geladen werden) |
| `.github/workflows/{deploy,release,security}.yml` | deaktiviert (`on:` nur `workflow_dispatch`) |
| `resources/views/components/layouts/app.blade.php` | Offline-Statusbereich für angemeldete App-Seiten; neutraler `appContextViews`-Einhängepunkt unter der Kopfzeile. Locations liefert darüber den Standortkontext aus einer Extension-View, ohne Modulnavigation in die globale Kopfzeile einzubauen. |
| `modules/Wasserkarte/resources/views/livewire/source-form.blade.php` | explizite Offline-Entwurf-Markierungen der Formularfelder |
| `public/sw.js` | Worker-Quelltext nach `extensions/Offline/resources/js/sw.js` verschoben; `/sw.js` liefert ihn jetzt mit korrekten Scope- und Cache-Headern aus |
| `tests/Feature/Notifications/PushTest.php` | prüft den gerouteten Service Worker statt einer statischen Datei |
| `README.md` | Dokumentationsstruktur, Inhaltsverzeichnis, Technologie-/Lizenz-Badges, verifizierte Fork-Erweiterungen und Betriebsunterschiede; fachliche Inhalte einschließlich Offline-Status und manueller Wiederherstellung von Wasserquellen-Entwürfen bleiben erhalten |
| `.dockerignore` | zusätzlich ausgeschlossen (Abschnitt „Fork“ am Ende, Upstream-Zeilen unverändert): Dokumentation samt Screenshots (`docs`, `docs-extended`, `*.md`), Entwicklungs- und CI-Konfiguration, `extensions/E2e` (nur für den Screenshot-Lauf) und lokaler Zustand eines Checkouts (Uploads in `storage/app`, `bootstrap/cache/*.php`, `auth.json`, Logs) |
| `CLAUDE.md` | aktualisiert den Speicherort und die Verantwortung für den Root-Service-Worker |
| `modules/Einsatzbericht/resources/views/livewire/report-editor.blade.php` | Einsatzbericht-Ergänzungen: ein `@includeIf('ebp::partials.editor', …)` (+ Kommentar) nach dem Abschnitt „Fahrzeuge und Besatzung“; ohne `extensions/EinsatzberichtPlus` bleibt es wirkungslos |
| `modules/Mitteilungen/{src/Models/Announcement.php,src/Services/AnnouncementService.php,src/Livewire/Manage.php,routes/web.php,resources/views/…,MitteilungenServiceProvider.php}` | Mitteilungen: expliziter Standortscope, Zugriffskontrollen und zusätzliche ntfy-/Matrix-Versandanforderung; Transportkonfiguration und -code liegen additiv in `extensions/Mitteilungen/` |
