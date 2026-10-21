# Repository-Audit – Zusammenfassung

**Stand:** 20. Oktober 2026 · **geprüfter Commit:** `b224e50ff629009135ef3ee900128be3a3af134f`

## Ergebnis

Die Erweiterung ist modular aufgebaut und verfügt über dokumentierte Integrationspunkte, automatisierte CI-Prüfungen und explizite Standortscopes für ausgewählte Modelle. Das implementierte Rechte- und Standortmodell erfüllt jedoch nicht das vorgegebene Ziel einer nachweisbaren, durchgängigen Mandantentrennung. Insbesondere ein vom Security-Review bestätigter standortübergreifender Objektzugriff sowie die ausdrücklich gemeinsame Semantik von `NULL`-Standorten verhindern eine Freigabe für den Betrieb mit der Annahme strikter Standortisolation.

**Empfehlung: No-Go für strikte Mehrstandort-Produktion**, bis SEC-01 privat behandelt, die NULL-Policy festgelegt und durchgängige positive wie negative Isolationstests für alle Datenpfade bestanden sind. Das bedeutet nicht, dass jede im Audit genannte Tabelle bereits einen konkreten Datenabfluss aufweist.

## Wichtigste Befunde

| ID | Priorität | Befund |
|---|---|---|
| SEC-01 | P1 | Verifizierter standortübergreifender Zugriff auf ein gespeichertes Objekt. Die konkrete Datei, Zeilen und Reproduktionsdetails sind in dieser öffentlichen Dokumentation gemäß `SECURITY.md:3-13` bewusst zurückgehalten. |
| TEN-01 | P1 | `LocationScope` macht Datensätze ohne Standortzuordnung ausdrücklich für eingeloggte Nutzer aller Standorte sichtbar (`extensions/Locations/Scopes/LocationScope.php:13-16,35-36`). |
| TEN-02 | P1 | Modulrollen enthalten keine Standortdimension (`module_user`: `database/migrations/2026_10_07_100001_create_module_user_table.php:11-17`); eine Rolle kann deshalb standortübergreifend wirken, sofern ein fachlicher Zugriff nicht zusätzlich begrenzt wird. |
| TEN-03 | P1 | Der globale Scope wird nur auf elf konfigurierte Modellklassen angewendet; Kind-, Pivot-, Datei-, Export- und Jobpfade sind nicht automatisch geschützt (`extensions/Locations/config/locations.php:9-21`). |
| TEN-04 | P2 | Für Hintergrundkontexte ohne eingeloggten Nutzer setzt `LocationScope` keinen Filter (`LocationScope.php:13-29`); Empfänger- und Datenabfragen müssen den Scope selbst durchsetzen. |
| PERF-01 | P2 | `AnnouncementService::recipients()` lädt Kandidaten und filtert danach je Nutzer über `canUseModule()`; dies ist ein möglicher N+1-Abfragepfad, nicht durch Messung quantifiziert (`modules/Mitteilungen/src/Services/AnnouncementService.php:100-123`, `app/Models/User.php:277-296`). |
| UX-01 | P2 | Der Standort-Umschalter ist laut Dokumentation nur im Admin-Menü verfügbar; ein jederzeit sichtbarer aktiver Standort ist nicht belegt (`docs-extended/LOCATIONS.md:45-46`). |

## Umfang und Grenzen

- Der bereitgestellte Checkout steht auf `copilot/extended-another-one`, Commit `b224e50…`; ein lokaler Branch oder Remote-Ref `extended` war nicht vorhanden. Es wurde nicht gewechselt. Die Befunde gelten daher exakt für diesen Checkout, nicht als Bestätigung des aktuellen Branches `extended`.
- Das Arbeitsverzeichnis war zu Beginn sauber. Es wurde ausschließlich die angeforderte Audit-Dokumentation unter `docs/audit/` erstellt.
- `composer audit --locked`, `npm audit --omit=dev`, `composer validate` und PHP-Syntaxprüfung von 1.570 PHP-Dateien waren erfolgreich.
- Pest, PHPStan, Pint und der Vite-Build konnten nicht laufen: `vendor/` und `node_modules/` fehlen; PostgreSQL auf `127.0.0.1:5432` war nicht erreichbar. PHP lokal ist 8.3.6, CI ist laut Workflow auf PHP 8.4 konfiguriert.
- Kein vollständiger Browser-/WCAG-Test, keine Laufzeit-Routeninventur, kein unabhängiger Penetrationstest und kein Upstream-Diff waren möglich. Entsprechende Punkte sind als offen gekennzeichnet.

## Reihenfolge

1. SEC-01 über den privaten Sicherheitsmeldeweg behandeln und mit einem nicht offenlegenden Regressionstest schließen.
2. `NULL`-Standorte und unklassifizierte Daten verbindlich behandeln; keine implizit gemeinsame Sicht für private Daten.
3. Standortbezogene Rollen und einen durchgängigen Policy-Entscheid für Modul, Aktion, Datenklasse und Scope definieren.
4. Parent-/Kinddaten, Suche, Dateien, Exporte, ICS, Benachrichtigungen und Jobs in dieselben Regeln einbeziehen.
5. Migrationen, positive und negative Regressionstests sowie Betriebs-/UI-Verbesserungen schrittweise ergänzen.

Die Befunde und konkrete Abnahmekriterien stehen in [02 Sicherheitsprüfung](02-security-audit.md), [03 Rollen und Scopes](03-roles-permissions-and-scopes.md), [06 Umsetzungsplan](06-implementation-plan.md) und [07 Testmatrix](07-test-and-acceptance-matrix.md).
