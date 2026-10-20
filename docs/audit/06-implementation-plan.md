# Priorisierter Umsetzungsplan für Copilot Plan Mode

Dieser Plan ändert keine Funktionalität im Rahmen des Audits. P1 bedeutet, dass Mehrstandortbetrieb erst nach erfolgreichem Abschluss der betroffenen Phase freigegeben werden sollte.

## Phase 0 — Sicherheitsbefund schließen (P1)

**Abhängigkeit:** Private Behandlung von SEC-01 gemäß `SECURITY.md:3-13`; keine Details in öffentlichen Issues/Commits.

- Abrufpfad so ändern, dass Objektzugriff den Parent-/Standortzugriff serverseitig prüft, nicht nur Login oder Modulzugang.
- Einheitliche Nichtoffenlegung für unzulässige Objekte.
- Regressionstest im lokalen Testsystem mit zwei Standorten, einem positiven und einem negativen Zugriff.
- **Risiko:** Eine zu grobe Parent-Prüfung könnte berechtigte Empfänger oder gemeinsam freigegebene Inhalte blockieren.
- **Akzeptanz / DoD:** Private Sicherheitsmeldung bearbeitet; Regressionstest besteht; Zugriff verweigert bei fremdem Scope ohne Metadatenleck; Security-Review bestätigt Korrektur.

## Phase 1 — Datenklassifikation und NULL-Regel festlegen (P1)

**Abhängigkeit:** Fachliche Entscheidung, welche Daten bewusst geteilt werden dürfen.

- Manifest je Tabelle/Modell mit Datenklasse, `standortgebunden | hybrid | übergreifend | bewusst global`, Parent-Vererbung und verantwortlichem Modul definieren.
- `NULL` für private/unbekannte Daten von bewusst gemeinsam unterscheiden; Schutzstandard ist deny-by-default.
- Altbestand per Dry-Run mit Anzahl, betroffenen Standorten, Konflikten und Zuordnungsvorschlag prüfen; danach kontrollierte Migration.
- Vor jeder Standortlöschung FK-, Historien-, Export- und Auditfolgen anzeigen.
- **Betroffene Bereiche:** `extensions/Locations/config/locations.php`, Location-Migrationen, neue Manifest-/Guard-Komponente, betroffene Modulmigrationen und Betriebsdokumentation.
- **Risiko:** Daten können bei falscher Zuordnung fehlen oder zu breit sichtbar werden; Migrationen können bestehende Installationen anders treffen als Neuinstallationen.
- **Akzeptanz / DoD:** Jede Anwendungstabelle hat eine geprüfte Klassifikation; private Tabellen erlauben keine unentschiedene NULL-Zuordnung; Dry-Run und Migrationsprüfung auf Neu- und Bestandsdaten bestanden.

## Phase 2 — Autorisierung und Rollen/Scopes zentralisieren (P1)

**Abhängigkeit:** Abgeschlossene Klassifikation aus Phase 1 und abgestimmte Rollenmatrix.

- Zentrale Capability-Prüfung für Nutzer, Modul, Aktion, Standort, Datenklasse und optionales Ablaufdatum entwerfen.
- Standortbezogene Modulrollen ergänzen; globale Modulrollen nur dort bestehen lassen, wo die fachliche Freigabe dies dokumentiert.
- Parent-/Kinddatensätze, Pivots, Rohabfragen, Detail-IDs, Dateien, Suche, Dashboards, Statistiken und Exporte auf denselben Policy-Entscheid umstellen.
- Benachrichtigungen, Webhooks, Scheduler, Queue und signierte Links erhalten expliziten Empfängerscope; Berechtigung zur Laufzeit erneut prüfen.
- **Betroffene Bereiche:** `app/Models/User.php`, `module_user` und zugehörige Migrationen, `extensions/Locations`, Services/Policies der Module und Export-/Notificationpfade.
- **Risiko:** Zu enge Regeln können legitime Modulprozesse blockieren; Übergangsrollen und Standortwechsel brauchen definierte Rückfall-/Supportpfade.
- **Akzeptanz / DoD:** Keine Fachautorisierung ausschließlich in UI; jeder geprüfte Zugriff entscheidet zentral und serverseitig; Rollen- und Standortentzug wirkt bei der nächsten Aktion.

## Phase 3 — Migrationen und automatisierte Berechtigungstests (P1)

**Abhängigkeit:** Capability-/Manifest-Verträge aus Phase 1 und 2.

- Schema-Guard als verpflichtenden CI-Test gegen alle fachlichen Tabellen ausführen; nicht klassifizierte neue Tabellen blockieren.
- Positive und negative Tests je Rolle, Datenklasse und Standort ergänzen.
- Direkte Livewire-/Webrouten-, Detail-ID-, Datei-, CSV/PDF/ICS-, Such-, Scan-, Mail-/Push- und Job-Zugriffe abdecken.
- Migrationen sowohl auf leerer Datenbank als auch auf repräsentativem Bestand ausführen; PostgreSQL-only Annahmen, Constraints und Rollback dokumentieren.
- **Betroffene Bereiche:** `tests/Feature/Extensions/Audit`, `tests/Feature/<Modul>`, `database/migrations`, CI-Workflow.
- **Risiko:** Ein Test, der nur Eloquent-Hauptmodelle abdeckt, verfehlt Raw Queries oder indirekte Datenpfade.
- **Akzeptanz / DoD:** Alle Testmatrixfälle in [07 Test- und Abnahmematrix](07-test-and-acceptance-matrix.md) sind automatisiert; PostgreSQL-CI grün; jeder kritische Fehler hat einen Regressionstest.

## Phase 4 — Rollenverwaltung und Standort-UX (P2)

**Abhängigkeit:** Stabiler Rollen- und Scope-Entscheid aus Phase 2.

- Zentrale Übersicht pro Person für Standortzuordnungen, Modulrollen, Vereinsrolle, Führungsprofil, Datenklassenfreigaben und Ablauf.
- Vergabe-/Entzugsvorschau mit Begründung, Gültigkeitsende, Auditspur und Dry-Run.
- Global sichtbare Statusleiste für aktiven Standort bzw. ausdrücklich geteilten Kontext.
- **Betroffene Bereiche:** Locations-Admin, Modulzugriffsverwaltung, Adminpanel und globale Layoutkomponenten.
- **Risiko:** Verwechslung eines Anzeigenamens mit einer wirksamen Capability; UI muss die serverseitige Wahrheit anzeigen.
- **Akzeptanz / DoD:** Zuweisung ist nachvollziehbar und widerrufbar; Standortkontext ist für datenverändernde Vorgänge sichtbar und per Screenreader benannt.

## Phase 5 — Qualität, Performance, A11y und Betriebsfähigkeit (P2/P3)

**Abhängigkeit:** P1-Autorisierung und CI-Tests abgeschlossen.

- Empfängerabfragen, Such-/Statistik-/Exportpfade und typische große Datenmengen messen; PERF-01 beheben, falls Query-Count-Messung N+1 bestätigt.
- Responsive, Tastatur-, Screenreader- und Kontrastprüfung für Auth, Standortwechsel, Rollenverwaltung, Dateien und Kalender.
- E2E-Prüfungen für Rollenentzug, Standortwechsel, Offlinecache, abgelaufene Freigaben und Fehlerzustände ergänzen.
- Für Jobs, Backups, Logs und Exporte Scope, Aufbewahrung, Zugriff und Wiederherstellung dokumentieren.
- **Akzeptanz / DoD:** Leistungsziele sind gemessen und dokumentiert; keine kritischen A11y-Blocker in den Kernflüssen; Restore-/Betriebsprüfung erfolgreich.

## Phase 6 — Upstream- und Fork-Wartbarkeit (P2/P3)

**Abhängigkeit:** Zugriffssicherheit bleibt bei jedem Upstream-Merge ein verpflichtender Regression-Gate.

- Upstream-Mergebase und Fork-Eingriffe pro Sync neu erfassen; Konfliktdateien und manuelle Eingriffe in `FORK.md` aktuell halten.
- Erweiterungen bevorzugt über Provider/Registries halten; Sicherheitsfixe im Upstream einbringen, wenn sie generisch sind.
- `upstream-sync`-Merge, Migrationen, Securitytests und Scope-Guard vor Merge ausführen.
- **Risiko:** Upstreamänderungen an Auth, Models und Migrationsreihenfolge können Scope-Annahmen aufheben.
- **Akzeptanz / DoD:** Jeder Sync dokumentiert Mergebase, Konflikte, betroffene Policy-/Scope-Verträge und grüne Regressionen.

## Freigabekriterien für Mehrstandortbetrieb

Freigabe erst, wenn SEC-01 behoben, jede Tabelle klassifiziert, private NULL-Daten ausgeschlossen/zugeordnet, alle P1-Fälle aus Phase 1–3 bestanden und Standort-/Rollenentzug über UI, direkte URL, Dateien, Exporte und Jobs getestet sind. Ein grüner Abhängigkeits-Audit allein ist kein Freigabenachweis.
