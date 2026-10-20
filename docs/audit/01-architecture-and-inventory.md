# Architektur und Inventar

## Prüfkontext

Geprüft wurde der bereitgestellte Snapshot `b224e50ff629009135ef3ee900128be3a3af134f`. Der ausgecheckte Branch heißt `copilot/extended-another-one`; `extended` war weder als lokaler Branch noch als Remote-Ref vorhanden. Der Audit wechselt den Branch nicht und erhebt keinen Anspruch, einen anderen Commit zu beschreiben.

Die Bestandsaufnahme kombiniert statische Inspektion und vorhandene Projekt-/Erweiterungsdokumentation. Eine vollständige Laufzeit-Routenliste war wegen fehlendem `vendor/autoload.php` nicht verfügbar.

## Systemaufbau

| Bereich | Ist-Zustand und Beleg |
|---|---|
| Backend | Laravel 12, PHP `^8.2`, Livewire 4 und Filament 5 (`composer.json:8-18`). Laravel-Webrouten, Livewire-Komponenten und Filament-Adminpanel; kein dokumentierter REST-API-Vertrag (`CLAUDE.md`, Abschnitt „Andere bemerkenswerte Entscheidungen“). |
| Frontend | Blade, Tailwind CSS 4, Alpine.js, Vite sowie FullCalendar, Leaflet und QR-Scanner (`package.json:6-28`). |
| Persistenz | PostgreSQL ist Testvoraussetzung (`phpunit.xml:27-33`); CI verwendet PostgreSQL 18 (`.github/workflows/fork-deploy.yml:24-37`). Migrations liegen im Core, in Modulen und Extensions. |
| Authentifizierung | Passwortlose Magic Links, optionale OIDC-Extension; Details und Grenzen in `CLAUDE.md` und `docs-extended/OIDC.md`. |
| Autorisierung | Aktiver Nutzer-/Modulzugriff über Middleware und `User`-Methoden; separate Modulrollen in `module_user`; optionale standortbezogene Erweiterung. |
| Module | 25 Module, explizit in `bootstrap/providers.php:6-30` registriert: Atemschutz, Ausrüstung, Einsatzbericht, Expositionen, Fahrtenbuch, Fahrzeugbuchung, Fahrzeuge, Finanzen, Führerscheine, Gefahrenmatrix, Gefahrstoffe, Kalender, Kleiderkammer, Lehrgänge, Mängelmanagement, Mitteilungen, Objektpläne, Personalverwaltung, Schichtplan, Schlüssel, Übungsdienste, Unterweisungen, Verbandbuch, Wartung und Wasserkarte. |
| Extensions | 10 Verzeichnisse unter `extensions/`: Anwesenheit, Dateien, E2E, EinsatzberichtPlus, Hydrantenkontrolle, Locations, Mitteilungen, Offline, OIDC und Vereinsverwaltung. `ExtensionsServiceProvider` erkennt Provider nach Verzeichnisname (`extensions/ExtensionsServiceProvider.php:15-26`); Liste auch in `FORK.md:71-80`. |
| Integrationen | Push, Kalender/ICS, E-Mail, QR-Scan, DIVERA-Webhooks, Dateien/Office, OIDC, Offline/PWA, ntfy/Matrix und Vereinsverwaltung sind in `FORK.md:71-80` beziehungsweise den Provider-/Routenbeschreibungen dokumentiert. Nicht jede Integration wurde zur Laufzeit getestet. |
| Hintergrundbetrieb | Laravel-Scheduler und modulare Commands; die konkrete Zeitplanung liegt verteilt in Core und Service Providern (`CLAUDE.md`, Abschnitt „Scheduler und editierbare E-Mail-Texte“). |
| Deployment | Docker, fork-spezifische Workflows und bedingtes Image-/Deployment-Verhalten (`.github/workflows/fork-deploy.yml:154-251`, `FORK.md:78-84`). |

## Modell- und Datenbestand

- Die Standort-Extension legt `x_locations` und `x_location_user` an und ergänzt `ext_location_id` nur bei einer expliziten Tabellenliste (`extensions/Locations/database/migrations/2026_12_01_000000_create_locations_tables.php`; `extensions/Locations/config/locations.php:9-21`).
- `module_user` speichert `user_id`, `module`, `role` und einen eindeutigen Schlüssel auf Nutzer/Modul (`database/migrations/2026_10_07_100001_create_module_user_table.php:11-17`).
- Gruppen- und Funktionsmitgliedschaften liegen in Pivot-Beziehungen; sensible Fachmodelle und deren Kinddaten nutzen modulare Tabellenpräfixe. Ein zentraler produktiver Katalog, der jeder Tabelle eine Zugriffsklasse zuordnet, wurde nicht gefunden.
- Die bestehende Audit-Testdatei versucht eine explizite Tabellenklassifikation einzufordern (`tests/Feature/Extensions/Audit/LocationIsolationAuditTest.php:30-64`). Sie wurde nicht ausgeführt; ihre tatsächliche Vollständigkeit gegen das aktuelle PostgreSQL-Schema ist offen.
- In `modules/` und `extensions/` wurden 111 Migrationsdateien gezählt; dies ist eine Dateizählung, kein Nachweis, dass sämtliche Upgradepfade erfolgreich sind.

## Autorisierung und Standortzugriff

1. `EnsureModuleAccess` prüft Modulaktivierung und Modulnutzung; die aktiven Rollen-/Zugangsregeln liegen in `app/Models/User.php:277-305`.
2. Standortmitgliedschaften/-rollen werden von `LocationAccess` aus `x_location_user` und dem primären Standort geladen (`extensions/Locations/Services/LocationAccess.php:24-38,95-106`).
3. Der Standort-Global-Scope wird nur für Klassen aus `config/locations.php` registriert (`extensions/Locations/LocationsServiceProvider.php:59-67`).
4. `LocationScope` lässt Standortlose ausdrücklich passieren und schaltet sich in nicht angemeldeten Kontexten ab (`extensions/Locations/Scopes/LocationScope.php:13-16,23-36`).
5. Vereinsverwaltung hat eine eigene Association-Zuordnung und prüft Vorstandszugriff explizit (`extensions/Vereinsverwaltung/Services/AssociationAccess.php:12-49`).
6. Dateien besitzen ein separates rekursives ACL-/Standortmodell (`extensions/Dateien/Services/FileAccess.php:59-137`); es ist kein automatischer Schutz für andere Module.

## Build, Test und Betrieb

- CI: Pest gegen PostgreSQL, Blade-Compile, Larastan, Composer/npm-Audits, Gitleaks und Trivy; PRs gegen `main` und `extended` werden in `fork-deploy.yml:3-14,70-152` abgedeckt.
- Upstream-Sync: wöchentlicher/ manueller Merge von `roberteinsle/openwehr@main` nach `upstream-sync` (`.github/workflows/upstream-sync.yml:1-18,27-63`).
- Upstream-Eingriffe und Fork-Regeln sind in `FORK.md:15-32,88-111` aufgeführt.
- Test-/Build-Limits dieses Audits sind in [04 Qualität und Performance](04-performance-and-quality.md) dokumentiert.

## Nicht verifiziert

- Vollständige Routen-, Scheduler-, Controller-, Filament- und Livewire-Inventur über die gestartete Anwendung.
- Vollständige Schema- und Fremdschlüsselmatrix sowie erfolgreicher Lauf aller Migrationen auf leerer und bestehender PostgreSQL-Datenbank.
- Upstream-Mergebase, tatsächlicher Diff gegen Upstream und Konfliktlast: im Checkout war keine Upstream-Remote-Referenz verfügbar.
- Reale Konfiguration aktivierter Extensions, Queue-/Scheduler-Betrieb und externe Systeme.
