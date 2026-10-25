# Mitteilungen: Standorte, ntfy und Matrix

[Zur Hauptdokumentation](../README.md#mitteilungen) · [Erweiterungsindex](README.md) · [Locations](LOCATIONS.md)

## Geltungsbereich und Empfänger

Beim Schreiben wählen Redakteure **Allgemein** oder genau einen Standort. Allgemeine Mitteilungen bleiben für alle aktiven Mitteilungen-Nutzer sichtbar, auch ohne Standort. Eine Standortmitteilung wird nur aktiven Nutzern angezeigt, deren `users.ext_location_id` oder `x_location_user`-Mitgliedschaft diesen Standort enthält; mehrere Mitgliedschaften werden berücksichtigt. Gruppen- und Funktionsauswahl schränkt diese Empfänger zusätzlich ein. Das gilt für Startseite, aktuelle Liste, Archiv, Lesebestätigung, Ausblenden, Anhänge, Statistik und Erinnerungen – unabhängig davon, welcher Nutzer oder welcher Scheduler die Abfrage ausführt.

Bestehende Mitteilungen erhalten bei der Migration `ext_location_id = NULL` und bleiben damit allgemein sichtbar; frühere Empfänger werden nicht rückwirkend ausgeschlossen. Die Migration legt bei aktivem Locations eine Fremdschlüsselbeziehung an und verhindert, dass ein Standort mit Mitteilungen gelöscht wird.

- Globale Admins behalten die bisherige standortübergreifende Verwaltung.
- Im standardmäßigen Legacy-Modus können Redakteure allgemeine Mitteilungen sowie Mitteilungen für Standorte verwalten, denen sie angehören.
- Im Legacy-Modus dürfen Standort-Admins nur ihre Standorte verwalten. Auch mit einer zusätzlichen Redakteur-Rolle können sie Mitteilungen nicht auf **Allgemein** oder einen fremden Standort umstellen.
- Als Empfänger zählt eine Person nur, wenn sie aktiv ist, das Modul nutzen darf und – bei Standortmitteilungen – tatsächlich Mitglied dieses Standorts ist. Globale Adminrechte allein machen niemanden zum Empfänger.

## Optionale Standort-Redakteursrollen (P2b)

`LOCATIONS_SCOPED_ROLE_MODULES=mitteilungen` aktiviert nach ausdrücklicher Vorbereitung die zielbezogenen Verwaltungsrechte. Ist Anwesenheit bereits umgestellt, lautet die gemeinsame Einstellung `LOCATIONS_SCOPED_ROLE_MODULES=anwesenheit,mitteilungen`. Standardmäßig ist kein Adapter eingeschaltet. Die Vergabe erfolgt durch globale Admins unter **Admin → Standorte → Standort bearbeiten → Standort-Modulrollen und Verlauf**, für Mitteilungen mit der Rolle **Redakteur** (`redakteur`), Begründung und optionalem Ablauf.

Im aktivierten Adapter benötigen Nicht-Admins eine gültige Vergabe am jeweiligen Zielstandort. Aktiver Nutzer, aktives Modul, aktiver Standort und tatsächliche Mitgliedschaft werden erneut geprüft; entzogene oder abgelaufene Vergaben gelten nicht. Alte globale Rollen, Autorenschaft und Standort-Adminrechte sind kein Verwaltungsfallback. Ein Standortwechsel benötigt Rechte am alten und neuen Ziel. Allgemeine Mitteilungen können dann nur globale Admins verwalten; deren standortübergreifende Rechte bleiben erhalten.

Diese Regeln betreffen auch direkte Dienstaufrufe, Änderungen, Löschen, Anhänge, Lesestatistik, Erinnerungen sowie Versandanforderungen. Normale Leserechte und Empfängerzielgruppen bleiben eigenständig: Eine Redakteursrolle erlaubt nicht automatisch den Empfang aller Mitteilungen. Die externe Kanalkonfiguration bleibt den bisherigen Kanalverwaltern vorbehalten.

**Hintergrundaufrufe:** Statistik, Erinnerungen, Mail/Push und externe Versanddienste benötigen im aktivierten Adapter einen ausdrücklich übergebenen, aktuell berechtigten Akteur. Ein Aufruf ohne Akteur wird abgewiesen; weder Scheduler noch Konsolenaufruf erhalten dadurch stillschweigend globale Rechte.

Es gibt keine automatische Übernahme alter Rollen und keine Aktivierung anderer Fachmodule. Beim Abschalten gelten wieder die Legacy-Verwaltungsregeln; das ist eine bewusste Berechtigungsänderung, kein sicherheitsneutraler Rückfall. Vorbereitung und Grenzen: [Locations](LOCATIONS.md#standortbezogene-modulrollen-gestufte-umstellung).

## ntfy und Matrix einrichten

Beide Kanäle sind standardmäßig aus und werden unabhängig konfiguriert. Unter **Admin → Mitteilungen → Externe Kanäle** richtet ein globaler Admin die allgemeinen Ziele und je Standort ein Ziel für ntfy und Matrix ein. Standort-Admins sehen und bearbeiten ausschließlich die Ziele ihrer eigenen Standorte. Leere Token-Felder behalten ein gespeichertes Token; die App zeigt dessen Wert nie wieder an. Tokens werden mit `APP_KEY` verschlüsselt in der Datenbank gespeichert.

Beispiele mit ausschließlich fiktiven Werten:

| Bereich | ntfy-Server / Topic | Matrix-Server / Raum |
|---|---|---|
| Allgemein | `https://ntfy.example.com` · `wehr-allgemein` | `https://matrix.example.com` · `!allgemein:example.com` |
| Wache Nord | `https://ntfy.example.com` · `wehr-nord` | `https://matrix.example.com` · `!nord:example.com` |

Die Serveradresse muss HTTPS verwenden und auf eine öffentlich erreichbare IP auflösen. Private, lokale und reservierte IP-Bereiche, eigene Ports, Pfade, Zugangsdaten in der URL und Weiterleitungen werden nicht unterstützt. Die App führt keine Verbindungstests beim Speichern aus; beim Versand werden Zieladresse und DNS-Auflösung erneut geprüft und die aufgelöste IP für die Anfrage fixiert. Authentifizierte Anfragen haben ein kurzes Zeitlimit und leiten keine Zugangsdaten über Weiterleitungen weiter.

**ntfy:** Es wird per HTTPS-POST an das Topic mit Bearer-Token gesendet. ntfy-Topics sind standardmäßig öffentlich erreichbar, sofern der Serverbetreiber sie nicht schützt. Vor dem Einschalten muss der ntfy-Betreiber daher den anonymen Zugriff verweigern (z. B. `auth-default-access: deny-all`) und jedem Topic nur den passenden Personen Zugriff geben. Unterschiedliche Topic-Namen allein sind keine Zugriffskontrolle. Siehe [ntfy Publish](https://docs.ntfy.sh/publish/) und [ntfy-Konfiguration](https://docs.ntfy.sh/config/).

**Matrix:** Der Bot muss Mitglied des ausdrücklich konfigurierten Raums sein und dort Nachrichten senden dürfen. Verwende private, einladungsbasierte Räume und lade nur die passende Zielgruppe ein. Die App fragt vor jedem Versand den Raumzustand ab und sendet **keinen** Klartext, wenn ein `m.room.encryption`-Event vorhanden ist. E2EE-Versand ist nicht implementiert; ein Fehler oder eine unklare Antwort bei der Zustandsabfrage führt ebenfalls dazu, dass nicht gesendet wird. Siehe die [Matrix Client-Server API](https://spec.matrix.org/latest/client-server-api/).

Die App synchronisiert weder ntfy-Berechtigungen noch Matrix-Mitgliedschaften mit Locations oder der Personalverwaltung. Betreiber müssen Personen beim Standortwechsel oder Austritt manuell aus externen Topics/Räumen entfernen und neue Mitglieder dort ausdrücklich hinzufügen. Bereits extern zugestellte Inhalte und deren Verlauf lassen sich durch die App nicht widerrufen.

## Versandverhalten und Datenschutz

In **Mitteilungen verwalten** fordert der Redakteur den ntfy- und Matrix-Versand für jede Veröffentlichung oder Änderung getrennt und ausdrücklich an. Die Mitteilung wird zuerst gespeichert und bleibt in der App verfügbar; ein Fehler eines externen Dienstes macht Speichern, Mail, Glocke oder Browser-Push nicht rückgängig. Der synchrone Versand benötigt keinen Queue-Worker. Die Oberfläche meldet Erfolg, fehlende Konfiguration, fehlende Berechtigungen, Serverfehler und verschlüsselte Räume ohne Zugangstoken oder Antwortinhalte offenzulegen.

- Allgemein wird ausschließlich an das allgemeine Ziel gesendet; eine Standortmitteilung ausschließlich an das Ziel dieses Standorts. Es gibt keinen Ersatzversand in einen allgemeinen Kanal oder einen anderen Standort.
- Titel und Text werden gesendet, aber keine Anhänge, Dateinamen, Zugangsdaten oder individuellen Aktionslinks. Der enthaltene App-Link führt auf `/mitteilungen` und erfordert weiterhin eine normale Anmeldung und passende Berechtigung; Lesebestätigung und Ausblenden bleiben in der App.
- Wenn eine Mitteilung Gruppen oder Funktionen als Zielgruppe hat, wird sie nicht extern gesendet. Ein gemeinsamer Topic-/Raum kann eine engere interne Zielgruppe nicht sicher abbilden; die App lockert diese Auswahl nicht.
- Noch nicht veröffentlichte oder abgelaufene Mitteilungen werden über keinen Kanal versendet – auch nicht per E-Mail, Glocke oder Browser-Push beim Speichern. Eine spätere Veröffentlichung wird nicht automatisch nachgeholt; ein berechtigter Redakteur muss die gewünschten Kanäle nach Beginn des Zeitraums ausdrücklich erneut anfordern.
- Erinnerungen an fehlende Lesebestätigungen werden nur an die einzelnen Empfänger über die bestehenden App-/Mail-Kanäle verschickt, nie in einen gemeinsamen Raum oder Topic.
- Gesendete Nachrichtenstände werden nach Mitteilung, Ziel und Inhalt protokolliert. Unverändertes erneutes Speichern erzeugt keine zweite Nachricht. Matrix-Wiederholungen verwenden dieselbe Transaktions-ID und sind begrenzt; ntfy wird bei einer unklaren Antwort nicht automatisch wiederholt, weil ntfy keinen hier verwendeten Idempotenzschlüssel garantiert.

Auch die Betreiber sind für externe Datenverarbeitung, Aufbewahrung, Backups, Raum-/Topic-Mitglieder und rechtliche Informationspflichten verantwortlich. Veröffentliche keine Inhalte, die in den konfigurierten externen Kanälen nicht für alle dort berechtigten Personen sichtbar sein dürfen.
