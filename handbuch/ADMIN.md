# Admin-Guide

[README](../README.md) · [Installationsguide](INSTALLATION.md) · [User-Guide](USER.md)

Dieser Guide führt durch die Einrichtung und den laufenden Betrieb in der Oberfläche. Server, Datenbank, Mailversand und Backups behandelt der [Installationsguide](INSTALLATION.md). Die vollständigen Fachabläufe stehen bei den [Modulvorstellungen](../README.md#module-im-detail).

## Inhaltsverzeichnis

- [Admin ist nicht gleich Fachrolle](#admin-ist-nicht-gleich-fachrolle)
- [Ersteinrichtung in sinnvoller Reihenfolge](#ersteinrichtung-in-sinnvoller-reihenfolge)
- [Mitglieder aufnehmen und einladen](#mitglieder-aufnehmen-und-einladen)
- [Module und Rechte vergeben](#module-und-rechte-vergeben)
- [Module startklar machen](#module-startklar-machen)
- [Im Alltag verwalten](#im-alltag-verwalten)
- [Austritt und Löschung](#austritt-und-löschung)
- [Zugriffsprobleme eingrenzen](#zugriffsprobleme-eingrenzen)

## Admin ist nicht gleich Fachrolle

![Admin-Bereich mit Dashboard](../docs-extended/screenshots/admin/dashboard.png)

**Globale Admins** verwalten den Kern und haben Zugriff auf aktive Module. **Modulverantwortliche** erhalten ihre Fachrechte über eine Modulrolle, etwa Kleiderwart oder Gerätewart. Sie brauchen dafür keine globalen Adminrechte. Der gemeinsame Admin-Bereich `/admin` zeigt ihnen nur die für sie erlaubten Verwaltungsbereiche; viele Fachaufgaben werden direkt im jeweiligen Modul erledigt.

**Standort-Admins** verwalten ihren Standort im vorgesehenen Umfang, sind aber keine globalen Admins. Eine Standortzuordnung ist auch kein pauschales Recht auf alle Fachdaten dieses Standorts. Rollenadapter und besondere Freigaben, etwa bei Dateien, bleiben zusätzlich relevant.

## Ersteinrichtung in sinnvoller Reihenfolge

1. **Grunddaten festlegen:** Unter **Admin → Einstellungen** Wehrname, Kurzname, Logo und Mail-Signatur setzen. Das eigene Wehrlogo und die installierbare WehrPortal-App-Marke sind getrennt.
2. **Standorte vorbereiten:** Bei Nutzung von Locations unter **Admin → Standorte** die benötigten Standorte anlegen. Mitglieder, zuständige Standort-Admins, Fahrzeuge und Ausrüstung passend zuordnen. Standortlose Daten können gemeinsam sichtbar bleiben; Locations ist keine vollständige Mandantentrennung.
3. **Abteilungen und Funktionen anlegen:** Abteilungen beschreiben Zugehörigkeiten, Funktionen Aufgaben. Eine Funktion wie Jugendwart ersetzt keine Modulrolle wie Ausbildungsleiter. Bei Locations die standortbezogene Abteilungsverwaltung beachten.
4. **Mitglieder erfassen:** Namen, Status und gegebenenfalls E-Mail pflegen; Abteilungen, Funktionen und Standorte zuordnen. Nur die benötigten personenbezogenen Felder verwenden.
5. **Module aktivieren:** Unter **Admin → Module** zunächst die wirklich benötigten Module einschalten und Fachverantwortliche festlegen. Alle Module folgen derselben bedarfsorientierten Auswahl.
6. **Stammdaten und Rechte einrichten:** Geräte, Fahrzeuge, Ablagen und andere benötigte Daten im jeweiligen Modul pflegen. Abhängigkeiten beachten, etwa Ausrüstung für Wartung.
7. **Mit normalem Konto prüfen:** Sichtbarkeit und typische Abläufe ohne Adminrechte durchgehen. Erst danach die Kameraden einladen.

## Mitglieder aufnehmen und einladen

Unter **Admin → Kameraden** ein Mitglied anlegen oder die vorhandenen Importmöglichkeiten nutzen. Personalverwalter können neue Mitglieder auch in ihrem Fachbereich aufnehmen.

**Mit eigener Anmeldung:** Eine erreichbare, persönliche E-Mail-Adresse und Status **Aktiv** hinterlegen. Über die Einladung eine Mail versenden oder den QR-Code vor Ort zeigen. Einladungen gelten sieben Tage und nur einmal; erst die Bestätigung im Browser meldet an. Der erste Login führt zur Willkommensseite.

**Ohne eigene Anmeldung:** Die E-Mail-Adresse leer lassen, etwa bei Kindern ohne eigenes Postfach. Das Mitglied bleibt in den Fachmodulen auswählbar, erhält aber keinen Login-Link und keine Einladung. Erziehungsberechtigte können Kalender-Mails erhalten und darüber für das Kind antworten; sie haben kein eigenes App-Konto.

Ein Mitglied kann mehreren Abteilungen angehören. Modulzugang und Fachrolle immer ausdrücklich prüfen; eine passende Funktionsbezeichnung allein vergibt sie nicht.

## Module und Rechte vergeben

Für Fachmodule sind drei Fragen zu unterscheiden:

1. **Ist das Modul aktiv?** Unter **Admin → Module** einschalten. Ein abgeschaltetes Modul ist nicht benutzbar.
2. **Darf die Person hinein?** Über **„x Kameraden“** die Mitglieder des Moduls öffnen und **Freigeschaltet** setzen. Alternativ die Modulzuordnung am Kameraden oder die Sammelaktionen verwenden.
3. **Was darf sie dort tun?** Eine Fachrolle vergeben. Zugang ohne Rolle bedeutet nicht automatisch Verwaltungsrecht.

Die Option **„Neue Kameraden automatisch freischalten“** steuert die automatische Freigabe; bestehende Mitglieder und deren Rollen trotzdem gezielt kontrollieren. Bei Sammelaktionen vor dem Bestätigen prüfen, ob nur markierte Personen oder **alle Gefilterten** betroffen sind.

**Zusätzliche Grenzen bleiben bestehen:** Dateiablagen benötigen eigene Lese-/Bearbeitungs-/Verwaltungsrechte. Vereinsvorstände werden einem konkreten Verein zugewiesen. Hydrantenwarte der Hydrantenkontrolle benötigen einen Ort im Profil; übergreifende Bearbeiter eine eigene Rolle. Fachrollen gelten nicht automatisch in ähnlich benannten Modulen.

Einige Module können durch den Betreiber zusätzlich per `*_ENABLED` gar nicht geladen werden. Dann genügt die Freischaltung in der Oberfläche nicht. Das ist eine technische Konfiguration, keine weitere Modulstufe.

**Standortrollen nicht unvorbereitet umstellen:** Die optionalen Adapter für Anwesenheit und Mitteilungen benötigen zuerst ausdrücklich vergebene Rollen am jeweiligen Zielstandort. Alte globale Fachrollen oder Standort-Adminrechte ersetzen diese bei aktiviertem Adapter nicht. Vorbereitung und weitere Opt-ins beschreibt das [Locations-Handbuch](../docs-extended/LOCATIONS.md).

## Module startklar machen

**Organisation:** Im Kalender Zielgruppen, Orte und Kleidung pflegen; Übungsdienste und Schichtpläne passend veröffentlichen. Für Anwesenheit bei Bedarf Vorlagen erstellen und als globaler Admin die Standard-Mailadressen prüfen. Mitteilungen zunächst an eine kontrollierte Zielgruppe testen. ntfy-/Matrix-Ziele erst einrichten, wenn die Mitgliedschaften der externen Räume oder Topics geklärt sind.

**Personal:** In der Personalverwaltung festlegen, welche persönlichen Daten geführt werden und welche Abteilungen die zuständigen Personen sehen dürfen. Diensthistorie und Lehrgänge prüfen, Nachweise einpflegen und den digitalen Dienstausweis bei Bedarf konfigurieren. Die Lehrgangsangebote aus fox112 müssen von der Ausbildungsleitung ausdrücklich angeboten werden.

**Fahrzeuge und Material:** Die gemeinsame Fahrzeugliste pflegen, dann bei Bedarf Fahrzeugprofile und Fristen ergänzen. Geräte und Kategorien in der Ausrüstung anlegen, bevor Wartungsvorlagen und Prüfaufträge eingerichtet werden. In Kleiderkammer und Schlüsselverwaltung erst Bestand und Kennzeichnungen erfassen, dann Ausgaben dokumentieren.

**Dateien:** Unter **Admin → Dateien** Ablagen und Kontingente anlegen, anschließend im Explorer die Rechte vergeben. Gruppenverwalter erhalten durch ihre Rolle keinen Dateizugriff. Öffentliche Freigaben sind standardmäßig aus; Online-Office braucht einen gesonderten Server. [Einrichtung und Betriebsgrenzen](../docs-extended/DATEIEN.md).

**Hydrantenkontrolle:** Hydrantenbestand und Ortszuordnung erfassen, dann Jahreskontrollen anlegen. Vorhandene Jahresdaten werden beim Ergänzen nicht überschrieben. Die Wasserkarte ist ein eigener Bestand, keine automatische Datenquelle für dieses Modul. [Bedienungsanleitung](../docs-extended/README.md#hydrantenkontrolle-bedienungsanleitung).

**Verein:** Unter **Admin → Vereine** Verein, Standort und Vorstand zuordnen. Vor öffentlichem Beitritt Beiträge, Datenschutz- und Einwilligungstexte mit den Vereinsvorgaben abgleichen. SEPA-Dokumentation ist keine Zahlungsabwicklung.

**Geschützte Dokumentation:** Bei Verbandbuch und Expositionen Zuständigkeiten, Aufbewahrung und organisatorische Abläufe vor der Nutzung klären. Gesperrte beziehungsweise unterschriebene Unterlagen nicht durch direkte Datenbankänderungen zu korrigieren versuchen.

## Im Alltag verwalten

**Rückfragen beantworten:** Unter **Admin → Feedback** neue Gespräche lesen, antworten und nach Klärung als erledigt markieren. Ein neuer Beitrag des Mitglieds kann ein Gespräch wieder öffnen.

**Mailversand beobachten:** Das Dashboard zeigt den Verbindungsstatus; **Jetzt prüfen** aktualisiert ihn. Ein erfolgreicher Verbindungstest ersetzt keinen tatsächlichen Empfangstest. Fehlgeschlagener Versand kann trotz gespeicherter Fachaktion vorkommen; die Aktion nicht ungeprüft erneut ausführen.

**Änderungen nachvollziehen:** Unter **Admin → Logs** nach Person, Bereich und Zeitraum filtern. Anmeldungen, Änderungen und versendete Mails sind dort nachvollziehbar; sensible Inhalte werden teilweise maskiert oder nicht gespeichert. Die reguläre Aufbewahrung beträgt 180 Tage, das Log ist kein dauerhaftes Facharchiv.

**Sicht eines Mitglieds prüfen:** **Als Kamerad ansehen** zeigt die App mit dessen Rechten. Der orange Balken führt zurück zum eigenen Admin-Konto. Währenddessen keine vermeintlichen Testaktionen ausführen: Änderungen geschehen tatsächlich im Namen des Mitglieds und werden protokolliert.

**Notfallzugang erhalten:** Mindestens einen funktionierenden lokalen Admin-Zugang erhalten, insbesondere bei Single Sign-On. Die mit `ADMIN_EMAILS` konfigurierten Adressen werden bei jedem Containerstart als aktive Admins angelegt beziehungsweise reaktiviert; beim Entzug solcher Rechte auch die Betreiberkonfiguration anpassen lassen.

## Austritt und Löschung

Bei Austritt grundsätzlich den Status **Ausgetreten** setzen. Dadurch endet der Login, während referenzierte Vorgänge erhalten bleiben. **Inaktiv** eignet sich für eine vorübergehende Deaktivierung.

Wenn andere Datensätze auf ein Mitglied verweisen, kann die normale Löschung blockiert sein. **Löschen mit Übertragung** ist eine bewusste, weitreichende Alternative: Der Dialog zeigt betroffene Bereiche und verlangt eine Zielperson sowie Bestätigung. Persönliche Daten werden teilweise gelöscht; geschützte Daten werden nicht beliebig auf eine andere Person übertragen. Vorher den [genauen Ablauf und die Ausnahmen](../README.md#admin-bereich-admin) lesen und eine Sicherung sicherstellen. Austritt ist keine Aufforderung, vorgeschriebene Aufbewahrungsfristen zu umgehen.

## Zugriffsprobleme eingrenzen

**Kein Login:** E-Mail-Adresse und Aktivstatus prüfen. Ohne E-Mail gibt es absichtlich keinen Login. Bei Mailproblemen den Betreiber hinzuziehen.

**Modul fehlt auf der Startseite:** Aktivierung und Modulzugang prüfen. Das Mitglied kann die Kachel auch selbst ausgeblendet haben; dann bleibt das Modul über das 3×3-Menü erreichbar.

**Modul sichtbar, Verwaltungsaktion fehlt:** Fachrolle, Standort und gegebenenfalls gesonderte Objekt- oder Ablagenrechte prüfen. Nicht vorschnell globale Adminrechte vergeben.

**Falscher Standort:** Zugehörigkeit, aktiven Standort und die Auswahl **Standort für neue Datensätze** prüfen. Diese Auswahl erweitert keine Rechte und filtert nicht sämtliche bestehenden Daten.

**Dateiablage leer:** Ablagenrechte und Standort prüfen; die Dateigruppen-Verwalterrolle allein genügt nicht. Nach einem Deployment auch die [Speichereinbindung](INSTALLATION.md#sichern-und-wiederherstellen) prüfen lassen.
