# Sicherheits- und Datenschutzprüfung

> Historischer Audit des Vorgängerprojekts vom 20.10.2026. Befunde und Verweise beziehen sich auf den damaligen Snapshot, nicht auf eine aktuelle WehrPortal-Abnahme; siehe [Migration und Betrieb](../../MIGRATION.md).

## Methodik und Grenzen

Read-only-Prüfung durch gezielte statische Inspektion plus unabhängigen Security-Review. Es wurden keine externen oder produktiven Systeme angegriffen. Der konkrete Nachweis SEC-01 wird wegen der Veröffentlichungspolitik in `SECURITY.md:3-13` nicht mit Endpoint, Objektart, Zeilen oder Reproduktion in dieses Repository kopiert. Die private Sicherheitsmeldung ist durch den Audit nicht übermittelt worden; die Maintainer müssen sie separat über GitHub Security einreichen.

## Findings

### SEC-01 — Standortübergreifender Zugriff auf ein gespeichertes Objekt

- **Schweregrad:** P1 · **Konfidenz:** hoch
- **Betroffene Komponente:** Ein authentifizierter, modulberechtigter Abrufpfad für gespeicherte Objekte; konkrete Datei/Zeilen sind für die öffentliche Dokumentation redigiert.
- **Evidenz:** Bestätigt durch den read-only Security-Review. Der präzise Nachweis muss über den privaten Meldeweg gemäß `SECURITY.md:3-13` an die Maintainer gehen.
- **Auswirkung:** Zugriff auf einen Datensatz außerhalb des zulässigen Standortbereichs; mögliche Offenlegung gespeicherter Inhalte.
- **Sichere Prüfung:** In einer lokalen Testinstallation zwei Standorte und Testnutzer anlegen; einen Abruf außerhalb der erlaubten Zuordnung als Negativtest ausführen. Keine fremden Instanzen und keine echten Mitgliedsdaten verwenden.
- **Lösung:** Vor jeder Ausgabe die Sichtbarkeit des fachlichen Parent-/Eigentümerdatensatzes im autorisierten Standortkontext prüfen; keine Berechtigung allein aus Login, Modulzugang oder erratbarer ID ableiten. Unzulässige Objekte einheitlich mit 404 verbergen.
- **Akzeptanz:** Ein Regressionstest belegt erlaubten Zugriff am zugeordneten Standort sowie verweigerten Zugriff an einem anderen Standort, ohne Objektinhalt oder Metadaten preiszugeben.
- **Offen:** Die fachliche Schutzklasse des betroffenen Objekts ist im öffentlichen Audit nicht offengelegt.

### TEN-01 — `NULL` ist explizit standortübergreifend sichtbar

- **Schweregrad:** P1 für zugesagte Standortisolation
- **Evidenz:** `extensions/Locations/Scopes/LocationScope.php:13-16,35-36`; `extensions/Locations/config/locations.php:7-8`; Gegenprobe ist als gewünschte Negativerwartung in `tests/Feature/Extensions/Audit/LocationIsolationAuditTest.php:11-28` formuliert.
- **Auswirkung:** Nicht zugeordnete private Datensätze werden wie bewusst geteilte Datensätze behandelt. Eine vergessene Zuordnung kann damit eine Standortgrenze umgehen.
- **Sichere Prüfung:** Testnutzer in Standort A; standortloser Testdatensatz; von Standort B prüfen, dass kein Treffer und keine Metadaten sichtbar sind. Der vorhandene Audit-Test ist nicht ausgeführt.
- **Lösung:** Unbekannt/unzugeordnet von zentral geteilt unterscheiden. Private Tabellen standardmäßig `NOT NULL` oder in Quarantäne; gemeinsam genutzte Einträge explizit typisieren und freigeben.
- **Akzeptanz:** Für jede Tabelle ist NULL-Semantik dokumentiert; private Tabellen lehnen NULL ab bzw. weisen Altbestand kontrolliert zu; zwei-Standort-Negativtests bestehen.

### TEN-02 — Modulrollen sind nicht an einen Standort gebunden

- **Schweregrad:** P1 für das Zielmodell
- **Evidenz:** `database/migrations/2026_10_07_100001_create_module_user_table.php:11-17` enthält keinen Standortbezug; `app/Models/User.php:299-305` fragt nur Modul und Rolle ab.
- **Auswirkung:** Fachcode, der `hasModuleRole()` ohne zusätzliche Standortprüfung als vollständige Autorisierung verwendet, kann die Rolle über alle Standorte hinweg auswerten. Das ist keine Aussage, dass jede Verwendung bereits einen Bypass enthält.
- **Sichere Prüfung:** Für jede Rolle positive und negative Tests an zwei Standorten ausführen; Mitgliedschafts- und Modulrollenentzug vor der nächsten Anfrage prüfen.
- **Lösung:** Standortgebundene Rollen-/Capability-Zuweisung oder gleichwertige zentrale Policy ergänzen; alte globale Zuweisungen migrieren und vor Aktivierung auf Konflikte prüfen.
- **Akzeptanz:** Capability-Entscheid enthält mindestens Nutzer, Modul, Aktion, Datenklasse und Standort; Entzug wirkt bei nächster Web-, Livewire- und Job-Aktion.

### TEN-03 — Global Scope ist eine begrenzte Modellliste, keine Mandantengarantie

- **Schweregrad:** P1 für Mehrstandortbetrieb
- **Evidenz:** `extensions/Locations/config/locations.php:9-21` listet elf Klassen; `LocationsServiceProvider.php:59-67` bindet nur diese Klassen ein. `CLAUDE.md` und `docs-extended/LOCATIONS.md:41-47` weisen zudem auf Raw-Query- und modulare Sonderpfade hin.
- **Auswirkung:** Kind-/Pivot-/Log-/Datei-/Such-/Export-/Aggregat-Abfragen werden nicht automatisch durch den Parent-Global-Scope geschützt.
- **Sichere Prüfung:** Jede Route, Livewire-Aktion, Adminabfrage, Suche, Export-, Datei-, Scan- und Benachrichtigungsabfrage für Parent und Kind mit einem zweiten Standort gegenprüfen.
- **Lösung:** Pro Tabelle Scope-/Datenklassenmanifest mit Parent-Vererbung etablieren; alle direkten IDs und Query-Builder-Abfragen müssen zentrale Policies verwenden. Ein Schema-Guard muss nicht klassifizierte Tabellen melden.
- **Akzeptanz:** Jede fachliche Tabelle ist klassifiziert; sämtliche Datenpfade bestehen positive und negative Isolationsfälle.

### TEN-04 — Hintergrundkontexte umgehen den Eloquent-Standortfilter

- **Schweregrad:** P2 (generelles Risiko; zusätzliche konkrete Pfade nicht vollständig inventarisiert)
- **Evidenz:** `LocationScope.php:13-29` kehrt bei fehlendem angemeldeten Nutzer ohne Filter zurück. Das ist für Console-/Scheduler-Kontexte ausdrücklich dokumentiert.
- **Auswirkung:** Jobs, Scheduler, Webhooks und tokenbasierte Aktionen erhalten keinen impliziten Standortschutz; Empfänger-/Datenabfragen müssen ihren Scope eigenständig sicherstellen.
- **Sichere Prüfung:** Tests für Jobs, Mail, Push, ICS, Webhooks und signierte Links nach Standortwechsel/-entzug und bei mehreren Teststandorten.
- **Lösung:** Standort-/Empfängerkontext bei Job-Dispatch explizit übergeben und vor Versand/Ausgabe erneut autorisieren; kein vertrauenswürdiger Nutzerkontext aus dem Dispatch-Zeitpunkt allein.
- **Akzeptanz:** Jeder asynchrone Pfad hat eine dokumentierte Scope-Quelle, widerruft unzulässige Empfänger zur Laufzeit und besitzt einen Cross-Location-Negativtest.

## Schutzmaßnahmen und offene Prüfungen

- Vereinsmitgliedsdaten einschließlich Kontakt- und Bankfelder nutzen Laravel-`encrypted`-Casts (`extensions/Vereinsverwaltung/Models/Member.php:21-39`).
- Vereinsvorstandszugriff wird über die Zuordnung zum Verein geprüft (`extensions/Vereinsverwaltung/Services/AssociationAccess.php:25-49`).
- Mitteilungen entfernen den globalen LocationScope bei der Empfängerauflösung und filtern anschließend explizit nach Standort (`modules/Mitteilungen/src/Services/AnnouncementService.php:100-123`). Das ist ein sicherheitskritischer Sonderpfad, der bei Änderungen eigene Regressionstests benötigt.
- Dateien besitzen eine eigene ACL-Vererbung und Standortprüfung (`extensions/Dateien/Services/FileAccess.php:67-137`); dieser Schutz ist nicht automatisch auf andere Ablagen übertragbar.
- `composer audit --locked` meldete keine Advisories; `npm audit --omit=dev` meldete keine bekannten Schwachstellen. Das ist kein Penetrationstest und kein Nachweis für sichere Laufzeitkonfiguration.
- **Nicht geprüft:** vollständige OWASP-ASVS-Prüfung, aktive Laufzeitkonfiguration, vollständige Rate-Limit-Abdeckung, Session-/Cookie-Verhalten im Browser, alle Upload-/Exportpfade, Image-Scan und unabhängiger Penetrationstest.
