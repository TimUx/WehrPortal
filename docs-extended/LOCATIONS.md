# Mehrere Standorte (Extension `Locations`)

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md) · [Fork-Leitfaden](../FORK.md)

Für Gemeinden mit mehreren Feuerwehrhäusern. Code: `extensions/Locations/`, Abschaltung mit `LOCATIONS_ENABLED=false`.

![Screenshot: Standorte im Admin-Bereich](screenshots/locations/standorte.png)

## Inhalt

- [Rollen](#rollen)
- [Datenmodell](#datenmodell)
- [Wirkung](#wirkung)
- [Schutzmanifest und Schema-Guard](#schutzmanifest-und-schema-guard)
- [Grenzen / offen](#grenzen--offen)

## Rollen

| Rolle | Wie | Sieht / darf |
|---|---|---|
| Globaler Admin | `users.is_admin` (unverändert) | alles, alle Standorte, legt Standorte an |
| Standort-Admin | `x_location_user.role = admin` | nur den eigenen Standort: Name, Mitglieder, Abteilungen und Zuordnung von Fahrzeugen und Ausrüstung (Admin → Standorte) |
| Mitglied | `x_location_user` oder `users.ext_location_id` | nur Daten des eigenen Standorts |

Standort-Admins erreichen `/admin` über `MyLocationPage` (`ModuleArea`), `User::canAccessPanel` bleibt unverändert. Core-Ressourcen (`AdminOnly`) bleiben globalen Admins vorbehalten.

## Datenmodell

- `x_locations`, `x_location_user`; Spalte `ext_location_id` (nullable, FK, `nullOnDelete`) an `users`, `groups`, `member_functions`, `vehicles`, `ar_equipment`, `ar_categories`, `kk_items`, `eb_reports`, `mm_defects`, `kal_events` (Liste in `config/locations.php`).
- Zeilen ohne Standort (`NULL`) gelten als gemeinsam und bleiben sichtbar.
- Die Migration legt den Hauptstandort an und ordnet vorhandene Daten zu; `app:ensure-locations` (Entrypoint `61-ensure-locations.sh`) und `Location::default()` stellen sicher, dass immer einer existiert. Der Hauptstandort und der letzte Standort sind nicht löschbar.

## Wirkung

- `LocationScope` (globaler Scope) filtert die konfigurierten Modelle auf die Standorte des angemeldeten Nutzers. Ohne angemeldeten Nutzer (Konsole, Queue, Scheduler, Login, Magic Link) und für globale Admins ist er aus.
- Neue Datensätze erhalten den aktuellen Standort (Umschalter im Benutzermenü, sonst eigener, sonst Hauptstandort); Schreiben in einen fremden Standort wirft `AuthorizationException`.
- Abteilungen gehören zu einem Standort. Standort-Admins legen sie in **Admin → Standorte → Standort bearbeiten** an und ordnen Mitglieder dort mehreren Abteilungen zu; dabei bleiben Zuordnungen zu anderen Standorten unverändert.
- `PersonnelService::visibleMembers()` und alle Listen, die über Eloquent laufen, sind damit automatisch standortbezogen.
- Bei Benutzern bleiben zusätzlich das eigene Konto und Mitglieder sichtbar, die über `x_location_user` einem der eigenen Standorte zugeordnet sind.
- Mitteilungen tragen eine eigene optionale `announcements.ext_location_id`-Spalte. `NULL` bedeutet allgemein; ein Standortwert beschränkt Listen, Anhänge, Bestätigungen und Empfänger auf tatsächliche Mitgliedschaften dieses Standorts. Die Mitteilungs-Migration fügt vorhandene Mitteilungen nicht rückwirkend einem Standort zu.

## Schutzmanifest und Schema-Guard

Das Schutzmanifest in `extensions/Locations/config/protection.php` (Konfigurationsschlüssel `locations.protection`) erfasst jede Anwendungstabelle ausdrücklich mit zuständigem Modul, Datenklasse, Zielmodus, Eltern-/Eigentümerbeziehungen, NULL-Regel und den zu prüfenden Autorisierungsmechanismen. Infrastruktur wird separat und abschließend benannt. Neue Tabellen dürfen nicht allein aufgrund ihres Namens oder Präfixes automatisch als freigegeben gelten.

**Klassifikation ist keine Autorisierung:** Das Manifest beschreibt die Schutzentscheidung und ihren Prüfstatus. Zielangaben sind noch keine Freigabe gemeinsamer Bestände oder personenbezogener Daten. Es registriert keine zusätzlichen Laufzeitfilter, migriert keine Daten und erteilt keine Rechte. Ein dokumentierter Zielmodus oder eine geplante NULL-Regel ändert daher nicht die oben beschriebene Ist-Semantik oder bestehende modulspezifische Ausnahmen. Globale Admins behalten Zugriff auf sämtliche Daten aller Standorte, einschließlich sensibler Inhalte.

„Bewusst global“ beschreibt den fachlichen Geltungsbereich, nicht eine öffentliche Freigabe. Auch globale Konfiguration, Authentisierungsdaten und Protokolle benötigen weiterhin ihre jeweiligen Zugriffsprüfungen.

`mode` und `null_rule` halten den vorgeschlagenen Zielzustand fest; `current_null_rule` und `parents[].source_null_rule` trennen davon das beobachtete Verhalten beziehungsweise die heutige Schema-Nullbarkeit. Fehlende Standortzuordnungen oder noch nötige Spalten/Migrationen werden als offene Zielarbeit dokumentiert, nicht als Genehmigung standortübergreifenden Zugriffs. Historische, nullable Personenverweise dürfen erhalten bleiben, ohne dadurch eine Freigabe zu bewirken.

`Extensions\Locations\Services\ProtectionManifest` prüft im bestehenden PostgreSQL-Audit-Test die tatsächlichen Tabellen gegen das Manifest. Fehlende Klassifikationen, ungültige Definitionen und fehlerhafte Elternbeziehungen blockieren die Prüfung. Der Fork-Workflow führt die Audit-Tests vor den übrigen Featuretests ausdrücklich aus; dieselben Tests bleiben zusätzlich Bestandteil der vollständigen Regression.

Optionale Tabellen dürfen nur fehlen, wenn die ausdrücklich benannte Erweiterung abgeschaltet ist; dafür enthält das Manifest eine endliche Tabellenliste, den Konfigurationsschalter und den zugehörigen Provider. Bereits vorhandene Tabellen werden auch nach dem Abschalten weiter geprüft. Entfernte oder umbenannte Migrationstabellen werden getrennt erfasst und dürfen nicht als Ausnahme für heute noch vorhandene Tabellen dienen.

Bei Änderungen an Migrationen muss die zugehörige Schutzentscheidung mit überprüft werden. Eigene Datei-ACLs, Vereinsrechte und personenbezogene Zugriffe bleiben eigenständige fachliche Schutzmechanismen; ein Eintrag im Manifest ersetzt ihre Tests nicht.

Eine bestandene Manifestprüfung belegt nur die Vollständigkeit und strukturelle Konsistenz der Klassifikation. Standortbezogene Modulrollen, Bestandszuordnung, Durchsetzung der Ziel-NULL-Regeln und positive wie negative Tests aller Fach-, Datei-, Export- und Hintergrundpfade bleiben eigene Abnahmen der folgenden Pakete.

## Grenzen / offen

- `DB::table(...)`-Abfragen umgehen den Scope; bei Upstream-Syncs prüfen.
- Eindeutige Schlüssel sind global (`eb_reports.number`, `ar_equipment.code`).
- Standortfilter in den übrigen Upstream-Formularen und -Tabellen gibt es nicht; Mitglieder und Abteilungen lassen sich unter Admin → Standorte zuordnen.
- Der Umschalter erscheint nur im Admin-Menü; für globale Admins steuert er nur, wo neue Datensätze landen.
- Verbandbuch, Mitteilungen und weitere Datenbereiche brauchen jeweils eine eigene Standortprüfung; ein Eintrag in `config/locations.php` allein begrenzt sie nicht. Mitteilungen sind über ihre explizite Standortspalte und `Announcement::for()` begrenzt.
