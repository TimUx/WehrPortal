# Fork-Leitfaden (openwehr-extended)

Dieser Fork erweitert [roberteinsle/openwehr](https://github.com/roberteinsle/openwehr) und soll dem Original so nah wie möglich bleiben, damit Updates konfliktarm übernommen werden können.

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

- `composer.json` / `composer.lock`: PSR-4 `Extensions\` → `extensions/` und `extra.laravel.providers`.

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

- **Container-Image/Release:** `deploy.yml`, `release.yml` und `security.yml` verweisen auf `ghcr.io/roberteinsle/openwehr` und das Coolify-Deployment des Originals. Im Fork gehört dort `IMAGE_NAME` auf das eigene (kleingeschriebene) Repository, z. B. `timux/openwehr-extended`. Diese Zeilen sind eine bewusste, dokumentierte Abweichung; bei Konflikten die eigene Variante behalten.
- **Version:** `config/app.php` → `version` bei Releases des Forks mit Suffix führen (z. B. `V.0.5.8-x1`), damit Upstream- und Fork-Stand unterscheidbar sind.
- **Lizenz:** AGPL-3.0 gilt weiter; Änderungen bleiben unter derselben Lizenz und der Quellcode muss Nutzern zugänglich sein.
- **Öffentliche Daten:** Die Regeln aus `CLAUDE.md` (keine echten Namen/Domains in Code, Tests, Docs) gelten auch hier.

## Liste der Upstream-Eingriffe

| Datei | Grund |
|---|---|
| `composer.json`, `composer.lock` | Autoload und Provider-Discovery für `extensions/` |
