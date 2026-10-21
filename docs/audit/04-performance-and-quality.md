# Performance, Tests und Codequalität

## Ausgeführte Prüfungen

| Prüfung | Ergebnis |
|---|---|
| `composer validate --no-check-publish --no-interaction` | Erfolgreich: Composer-Metadaten gültig. |
| `composer audit --locked --no-interaction` | Erfolgreich: keine bekannten PHP-Dependency-Advisories gemeldet. |
| `npm audit --omit=dev --audit-level=high` | Erfolgreich: 0 bekannte Vulnerabilities in geprüften Produktionsabhängigkeiten. |
| PHP-Syntax `php -l` | Erfolgreich für 1.570 PHP-Dateien in `app`, `bootstrap`, `config`, `database`, `extensions`, `modules`, `routes` und `tests`. |
| `./vendor/bin/pest --parallel` | Nicht ausführbar: `vendor/bin/pest` fehlt. |
| PHPStan / Pint | Nicht ausführbar: Composer-`vendor/` fehlt. |
| `npm run build` | Nicht ausführbar: `node_modules` fehlt, `vite: not found`. |
| PostgreSQL-Tests | Nicht ausführbar: `127.0.0.1:5432` antwortete nicht. |
| Browser-/E2E- und Lasttests | Nicht ausgeführt. |

Die Laufzeitumgebung enthält PHP 8.3.6; der Fork-CI-Workflow richtet PHP 8.4 ein (`.github/workflows/fork-deploy.yml:43-48`). Kein Test-/Build-Artefakt wurde installiert oder eingecheckt.

## CI- und Wartbarkeitsbefunde

- Der Fork-Workflow führt Datei-Featuretests, Blade-Kompilierung und parallele Regressionstests gegen PostgreSQL aus; Security-Job umfasst Composer/npm-Audit, Larastan und Gitleaks (`fork-deploy.yml:70-152`).
- Der Build-/Push-Job ist an Pushes gebunden und scannt das Image vor dem Push mit Trivy (`fork-deploy.yml:154-220`).
- Ein separater wöchentlicher Workflow prüft Abhängigkeiten und das veröffentlichte Image (`.github/workflows/fork-security.yml:6-55`).
- `phpstan.neon` verwendet Level 5 plus Baseline; `phpunit.xml` setzt PostgreSQL als Testdatenbank (`phpstan.neon:1-16`, `phpunit.xml:27-33`).
- Die Upstream-Dateien, die im Fork geändert werden, sind in `FORK.md:88-111` dokumentiert; diese Liste reduziert, ersetzt aber keinen tatsächlichen Diff gegen einen Upstream-Mergebase.

## PERF-01 — Möglicher N+1-Abfragepfad bei Mitteilungs-Empfängern

- **Schweregrad:** P2 · **Status:** statisch begründetes Risiko, kein gemessener Performancefehler
- **Evidenz:** `AnnouncementService::recipients()` lädt aktive Nutzer und filtert danach in PHP über `canUseModule('mitteilungen')` (`modules/Mitteilungen/src/Services/AnnouncementService.php:100-123`). Bei Nutzer ohne Auto-Assign führt `canUseModule()` eine `module_user`-Existenzabfrage aus (`app/Models/User.php:277-296`).
- **Auswirkung:** Zusätzliche Datenbankabfragen können mit der Anzahl Kandidaten skalieren; tatsächliche Wirkung hängt von Cache, Mitgliederzahl und Queryplan ab und wurde nicht gemessen.
- **Prüfung:** Query-Count und Laufzeit mit repräsentativen Daten für allgemeine und standortgebundene Mitteilungen messen; zusätzlich korrekte Empfängermenge testen.
- **Vorschlag:** Berechtigte Empfänger möglichst datenbankseitig einschränken oder eine set-basierte Abfrage nutzen; Sicherheitsfilter dürfen dabei nicht entfallen.
- **Akzeptanz:** Abfragezahl bleibt bei wachsender Kandidatenmenge bounded, Empfänger bleiben identisch, beide Standort-Negativtests bestehen.

## Performance- und Qualitätslücken

- Raw Query Builder und `withoutGlobalScope()` kommen in Fachpfaden vor. Das ist nicht per se unsicher, entzieht Abfragen aber dem Eloquent-Standortscope; jeder Pfad braucht explizite Autorisierung. Beispiele: `AnnouncementService.php:100-123`, `FileAccess.php:67-137`.
- Für DB-Indizes, N+1-Fälle über das gesamte System, Query-Plan-Stabilität, große Exporte, Speichergrenzen, Job-Rückstau und Seitengrößen wurde keine Messung durchgeführt. Es werden keine Laufzeitwerte behauptet.
- Der Test auf Tabellenklassifikation ist vorhanden (`tests/Feature/Extensions/Audit/LocationIsolationAuditTest.php:30-64`), wurde aber nicht gegen PostgreSQL ausgeführt.
- Eine vollständige Abdeckung neuer Tabellen und Upstream-Syncs ist erst durch CI-Lauf und schema-basierten Guard nachgewiesen, nicht durch den statischen Audit.

## Empfehlungen

1. CI in der vorgesehenen PHP-8.4-/PostgreSQL-18-Umgebung vollständig ausführen; fehlende Abhängigkeiten/DB nicht als bestanden werten.
2. Tenant-/Rollen-Regressionssuite als Pflicht-Gate aufnehmen; unklassifizierte Tabellen müssen den Merge verhindern.
3. Query-Count-Tests oder Benchmark-Datensätze für zentrale Empfänger-/Such-/Exportpfade ergänzen.
4. Migrationspfade gegen leere und aktualisierte Datenbanken sowie Rollback-/Wiederherstellungsstrategie testen.
5. Toolchain und Baselines aktuell halten; Composer/npm-Audit nicht mit Laufzeit-Sicherheitsnachweisen gleichsetzen.
