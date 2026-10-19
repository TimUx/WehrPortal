# Erweiterungen von openwehr-extended

[Zur Hauptdokumentation](../README.md) · [Fork-Leitfaden](../FORK.md)

Die Erweiterungen ergänzen die 25 Module des Upstreams. Sie werden über den Erweiterungs-Provider geladen; ob ein Modul für Kameraden verfügbar ist, wird davon getrennt unter **Admin → Module** gesteuert. Die Umgebungsvariablen in der Tabelle schalten die jeweilige Erweiterung selbst ab, sofern nicht anders angegeben.

## Inhalt

- [Übersicht](#übersicht)
- [Hydrantenkontrolle: Bedienungsanleitung](#hydrantenkontrolle-bedienungsanleitung)
- [Betrieb und Planung](#betrieb-und-planung)

## Übersicht

| Erweiterung | Zweck | Zugang, Aktivierung und wichtige Grenzen |
|---|---|---|
| [Hydrantenkontrolle](#hydrantenkontrolle-bedienungsanleitung) | Stammdaten, fortsetzbare Jahreskontrollen, Kontrollhistorie je Hydrant, Mängelcheckliste und echte PDF-Berichte. | Unter Admin → Module aktivieren und Rolle `hydrantenwart` vergeben (Admins ebenfalls berechtigt). `HYDRANTENKONTROLLE_ENABLED=false` lädt die Erweiterung nicht. Jahresbezogene Stammdatenkopien, Konfliktschutz beim Speichern, optionale Standortbegrenzung; keine automatische Wasserkarte-/Excel-Synchronisierung. |
| [Locations](LOCATIONS.md) | Mitglieder und ausgewählte Datensätze Standorten zuordnen. | `LOCATIONS_ENABLED=false` deaktiviert Scope und Standortverwaltung. Nur konfigurierte Modelle werden begrenzt; Standortloses (`NULL`) bleibt gemeinsam sichtbar. Keine vollständige Mandantentrennung. |
| [Offline/PWA](OFFLINE.md) | Offline-Fallback sowie lokale Entwürfe ausdrücklich markierter Formulare. | `OFFLINE_ENABLED=false` schaltet Statusanzeige und Entwürfe ab, aber nicht Manifest, Service Worker und Fallback-Seite. Keine automatische Übermittlung oder Synchronisierung von Formularen; Objektpläne nutzen einen separaten Worker. |
| [OIDC](OIDC.md) | Anmeldung über einen externen OpenID-Connect-Anbieter. | `OIDC_ENABLED=false` lädt OIDC nicht. Nur aktive Konten mit bestätigter E-Mail werden verknüpft; JIT-Anlage ist optional, IdP-Rollen werden nicht übernommen. Bei „Nur Single Sign-On“ lokale System-Admins und Notfallzugang erhalten. |
| [Anwesenheit](ANWESENHEIT.md) | Eigenständige Anwesenheitslisten, Vorlagen, PDF und E-Mail. | Modul nach Migration zunächst inaktiv; `ANWESENHEIT_ENABLED=false` schaltet die Erweiterung ab. Optional: Kalender zur Vorbelegung, Locations zur Standortbegrenzung und Offline für lokale Formularentwürfe. |
| [Dateien](DATEIEN.md) | Private Dateiablagen mit ACL, Versionen, Suche und optionaler Office-Bearbeitung bzw. öffentlicher Lesefreigabe. | Modul nach Migration zunächst inaktiv. Es gibt keinen `DATEIEN_ENABLED`-Schalter. Zugriff wird zusätzlich zu Modulzugang pro Ablage/Eintrag vergeben; öffentliche Links sind standardmäßig aus. Online-Office benötigt einen separat betriebenen Server. |
| [EinsatzberichtPlus](EINSATZBERICHT.md) | Zusätzliche Einsatzangaben und ein nicht blockierender Sollbesatzungshinweis. | `EINSATZBERICHT_PLUS_ENABLED=false` schaltet die Erweiterung ab. Nutzt die Rollen und Standortgrenzen des Einsatzberichts; Zusatzangaben sind derzeit nicht Teil von Druck oder Statistik. |

## Hydrantenkontrolle: Bedienungsanleitung

[Kurzüberblick in der README](../README.md#hydrantenkontrolle)

![Screenshot: Hydrantenkontrolle – Jahreskontrollen](screenshots/hydrantenkontrolle/jahreskontrollen.png)

Die Hydrantenkontrolle ist ein eigenes Fork-Modul für die jährliche Prüfung von Unterflurhydranten. Sie führt einen eigenen Bestand und ist unabhängig vom Modul **Wasserkarte & Hydranten**.

### Einrichtung und Rechte

1. Die neue Anwendungsversion mit den Datenbankmigrationen installieren; vor einem Update die Datenbank sichern. Im Produktionscontainer laufen Migrationen beim Start, bei einer manuellen Installation mit `php artisan migrate --force`.
2. Die Erweiterung ist standardmäßig geladen. `HYDRANTENKONTROLLE_ENABLED=false` schaltet ihren Provider und ihre Routen ab; gespeicherte Daten werden dadurch nicht gelöscht. Nach Änderungen an Umgebungsvariablen die Anwendung mit aktualisierter Konfiguration neu starten.
3. Unter **Admin → Module** das zunächst inaktive Modul **Hydrantenkontrolle** einschalten.
4. Den zuständigen Kameraden Modulzugang und die Modulrolle **Hydrantenwart** (`hydrantenwart`) geben. Aktive globale Admins dürfen das aktive Modul ohne zusätzliche Rollenzuweisung nutzen.
5. Das Modul über die Startseite beziehungsweise das App-Menü öffnen. Die Modulleiste enthält **Jahreskontrollen** und **Hydranten**.

Modulzugang allein genügt nicht: Auch das Lesen der Übersichten und der PDF-Export erfordern die Rolle oder globale Adminrechte. Eine gleichnamige Rolle im Modul Wasserkarte gilt nicht automatisch für die Hydrantenkontrolle. Standort-Adminrechte allein ersetzen die Modulrolle ebenfalls nicht.

Bei aktivem **Locations** vor dem Erfassen den gewünschten Standort auswählen. Neue Hydranten und neue Jahreskontrollen gehören zum aktuellen Standort; **Öffnen / ergänzen** nimmt nur dessen aktive Hydranten auf. Sichtbare Jahre werden mit Standort angezeigt. Standortlose Datensätze bleiben nach dem Fork-Muster gemeinsam sichtbar; dies ist keine vollständige Mandantentrennung.

### Hydrantenbestand erfassen

Unter **Hydranten → Hydrant erfassen** (`/hydrantenkontrolle/hydranten/neu`) folgende Stammdaten eintragen:

| Feld | Regel |
|---|---|
| Nummer | Pflichtfeld, Text mit höchstens 100 Zeichen; pro Standort eindeutig, auch bei archivierten Hydranten. Führende Nullen sind möglich. |
| Straße | Optional, höchstens 255 Zeichen; kann zusammen mit der Hausnummer die Adresse beschreiben. |
| Hausnummer | Optional, höchstens 50 Zeichen, auch mit Buchstaben; allein keine ausreichende Ortsangabe. |
| Lagebeschreibung | Optional, höchstens 10.000 Zeichen, etwa „Am Musterplatz neben dem Gerätehaus“. |
| Breitengrad / Längengrad | Optional, aber immer gemeinsam; Breitengrad −90 bis 90, Längengrad −180 bis 180. |
| Aktiv | Neue Hydranten sind aktiv. Abwählen archiviert den Hydranten für künftige Aufnahmen in Jahreskontrollen. |

Mindestens **Straße oder Lagebeschreibung oder ein vollständiges Koordinatenpaar** muss vorhanden sein. Es gibt keine automatische Adresssuche oder Kartenpositionierung.

In der Bestandsliste nach Nummer, Straße oder Lagebeschreibung suchen und zwischen **Aktiv**, **Archiviert** und **Alle** wechseln. Ein Klick auf den Hydranten öffnet die Stammdatenbearbeitung; **Historie** zeigt alle gespeicherten Jahreskontrollen. Der Standort lässt sich dort nicht nachträglich ändern.

### Ein Jahr beginnen und an weiteren Tagen fortsetzen

1. **Jahreskontrollen** (`/hydrantenkontrolle`) öffnen.
2. Unter **Jahr anlegen oder fehlende aktive Hydranten ergänzen** das gewünschte Jahr eingeben; die Oberfläche erlaubt 2000 bis 2100.
3. **Öffnen / ergänzen** wählen. Für jeden noch fehlenden aktiven Hydranten entsteht ein ungeprüfter Kontrollsatz mit einer Kopie seiner aktuellen Stammdaten.
4. Am Hydranten **Bearbeiten** wählen, Prüfdatum und Ergebnis eintragen und ausdrücklich **Kontrolle speichern** drücken. Es gibt kein automatisches Speichern.
5. Am nächsten Tag über den vorhandenen Jahreslink fortfahren. Gespeicherte Prüfdaten und Bemerkungen werden beim Bearbeiten wieder geladen.

Pro Standort und Jahr gibt es eine Jahreskontrolle, pro Hydrant darin genau einen Kontrollsatz; die Datenbank verhindert Duplikate. **Öffnen / ergänzen** darf wiederholt werden: Es ergänzt neue oder inzwischen aktivierte Hydranten, überschreibt aber keine vorhandenen Prüfdaten und keine bereits aufgenommenen Stammdaten.

Ein neu angelegtes Folgejahr erhält eigene, zunächst ungeprüfte Kontrollsätze. Ergebnisse und Bemerkungen aus Vorjahren werden nicht übernommen. Ein bereits in einer Jahreskontrolle enthaltener Hydrant bleibt dort auch nach seiner Archivierung erhalten.

### Ergebnis und vollständige Mängelcheckliste

Das Prüfdatum muss im ausgewählten Kontrolljahr liegen. Beim Speichern wird der angemeldete Kamerad als Prüfer eingetragen; bei späterer Bearbeitung wird diese Angabe aktualisiert. Ein anderer Prüfer lässt sich nicht manuell auswählen.

Die folgenden zehn Mängel können einzeln oder gemeinsam markiert werden:

1. Unterflurhydrant nicht aufgefunden
2. Unterflurhydrant außer Betrieb gesetzt
3. Hydrantendeckel lässt sich nicht öffnen
4. Steg vom Hydrantendeckel gebrochen
5. Hydrantendeckel schließt nicht
6. Fußventil undicht
7. Hydrant entwässert nicht
8. Bolzen von Deckel nicht vorhanden
9. Standrohr/Schlüssel lassen sich nicht aufsetzen
10. Hinweisschild unleserlich/falsch/fehlt

Alternativ **Unterflurhydrant in Ordnung** wählen. Dieses Häkchen darf nicht mit Mängeln kombiniert werden. **Bemerkungen** sind ein separates Freitextfeld mit höchstens 10.000 Zeichen.

| Zustand | Eingabe |
|---|---|
| Ungeprüft | Kein Prüfdatum, kein Mangel und kein Häkchen bei „In Ordnung“; Bemerkungen sind bereits möglich. |
| In Ordnung | Prüfdatum und „Unterflurhydrant in Ordnung“, keine Mängel. |
| Mit Mängeln | Prüfdatum und mindestens ein Mangel, „In Ordnung“ nicht markiert. |

Ein Prüfdatum ohne Ergebnis oder ein Ergebnis ohne Prüfdatum wird abgewiesen. Um eine irrtümliche Prüfung wieder auf **Ungeprüft** zu setzen, Prüfdatum und sämtliche Häkchen entfernen und speichern.

### Übersicht, Kontrollhistorie und Korrekturen

Die Jahresansicht zeigt **Gesamt**, **Ungeprüft**, **In Ordnung** und **Mit Mängeln**. Suche nach Nummer oder Straße und Statusfilter begrenzen die angezeigte Liste; die Kennzahlen bleiben auf das ganze ausgewählte Jahr bezogen. Über **Historie** beim Hydranten öffnet sich eine Jahresübersicht mit dem zuletzt gespeicherten Status, Prüfdatum, Prüfer, Mängeln und Bemerkungen. Sie ist sowohl in der Hydrantenliste als auch neben **Bearbeiten** in der Jahresansicht erreichbar.

Stammdatenänderungen wirken nicht rückwirkend auf bestehende Kontrollsätze. Beispiel: Wird „Musterstraße 12“ später zu „Musterstraße 14“ korrigiert, zeigt eine bereits angelegte Jahreskontrolle weiterhin ihre ursprüngliche Kopie. Auch erneutes **Öffnen / ergänzen** aktualisiert diese Kopie nicht. Deshalb die Stammdaten möglichst vor Beginn der Jahreskontrolle prüfen.

Bei zeitgleicher Bearbeitung desselben Datensatzes wird ein veralteter Speicherstand abgewiesen. Eigene noch nicht gespeicherte Angaben zunächst sichern, die Seite neu laden, den inzwischen gespeicherten Stand prüfen und die gewünschte Korrektur erneut vornehmen. Es gibt keine automatische Zusammenführung.

Die Jahresdaten bleiben bearbeitbar; es gibt keine endgültige Freigabe oder Versiegelung. Die Kontrollhistorie zeigt je Jahr nur den zuletzt gespeicherten Stand, keine einzelnen Revisionen. Änderungen innerhalb eines Jahres werden im normalen Aktivitätslog mit dessen regulärer Aufbewahrungsfrist dokumentiert. Die Jahresstände bleiben erhalten, sind aber nicht mit einer unbegrenzt aufbewahrten Änderungshistorie gleichzusetzen.

### PDF als Zwischenstand oder Jahresbericht

Im gewünschten Jahr oben **PDF-Bericht** wählen. Der Export liefert eine echte PDF-Datei (`application/pdf`) im A4-Format, nicht lediglich eine Browser-Druckansicht.

Enthalten sind:

- Organisationsname und gegebenenfalls Standort;
- Kontrolljahr und Erstellungsdatum mit Uhrzeit in **Europe/Berlin**;
- Kennzahlen und Prüfübersicht, einschließlich ungeprüfter Hydranten;
- pro Hydrant die eingefrorenen Stammdaten, Koordinaten, Prüfdatum, Prüfer, Ergebnis, ausgewählten Mängel und Bemerkungen.

Der Export umfasst immer die gesamte gewählte Jahreskontrolle, auch bei aktiver Suche, gesetztem Statusfilter oder mehreren Listenseiten. Er ist jederzeit als Zwischenstand möglich. Vor einem Jahresabschluss insbesondere den Filter **Ungeprüft** kontrollieren und offene Prüfungen gegebenenfalls nachholen. Das Herunterladen schließt oder sperrt das Jahr nicht; die heruntergeladene Datei hält nur den jeweiligen Exportstand fest.

### Häufige Fragen und Grenzen

| Frage | Erklärung / Vorgehen |
|---|---|
| Warum erscheint das Modul nicht? | Prüfen, ob der Provider geladen, die Migration ausgeführt und das Modul aktiviert ist; anschließend Modulzugang und Rolle prüfen. |
| Warum fehlt ein neuer Hydrant im Jahr? | Aktiv-Status und aktuellen Standort prüfen, dann das betreffende Jahr über **Öffnen / ergänzen** erneut öffnen. Der vorhandene Jahreslink allein ergänzt keine Kontrollsätze. |
| Warum ändern sich Adresse oder Lage im alten Jahr nicht? | Jahreskontrollen behalten absichtlich ihre Stammdatenkopie; Stammdatenkorrekturen überschreiben keine historischen Kontrollsätze. |
| Warum kann eine Nummer nicht erneut vergeben werden? | Nummern sind am Standort auch für archivierte Hydranten belegt. Den vorhandenen Datensatz suchen und bei Bedarf reaktivieren. |
| Kann ich die Exceldatei importieren oder Daten aus der Wasserkarte übernehmen? | Nein. Es gibt keinen Excel-Import und keine automatische Kopplung an die Wasserkarte. Die Mängelcheckliste bildet die genannten Excel-Spalten ab; der Bestand wird separat erfasst. |
| Funktioniert die Erfassung offline? | Es gibt für dieses Modul keine Offline-Synchronisierung oder automatische Wiederherstellung ungespeicherter Eingaben. Änderungen regulär online speichern. |
| Werden Mängel automatisch weitergemeldet? | Es gibt hier keine automatische E-Mail-Meldung oder Übergabe an das Mängelmanagement. Feststellungen im Modul und im PDF dokumentieren und die weitere Bearbeitung organisatorisch veranlassen. |

Der PDF-Export verwendet die bereits vorhandene Dompdf-Bibliothek und benötigt keinen zusätzlichen PDF-Dienst. Hydranten, Jahreskontrollen und Prüferangaben gehören zur Datenbank; deren regelmäßige Sicherung ist erforderlich. Ein exportiertes PDF ersetzt keine vollständige Datensicherung.

## Betrieb und Planung

- Erweiterungen und Module haben getrennte Aktivierung: ein aktiver Provider bedeutet nicht, dass ein Kamerad automatisch Zugriff auf ein Modul oder eine Dateiablage hat.
- Die Standortbegrenzung umfasst nur die konfigurierten Modelle und schützt nicht automatisch jede SQL-Abfrage. Zu den Grenzen siehe [Locations](LOCATIONS.md).
- Offline-Entwürfe bleiben lokal im Browser, laufen ab und müssen von der Person geprüft und regulär abgesendet werden. Es gibt keine automatische Formularsynchronisierung; siehe [Offline/PWA](OFFLINE.md).
- Die Dateien-Roadmap trennt umgesetzte Meilensteine von offenem Ausbau; geplante Punkte sind keine verfügbaren Funktionen: [Dateien-Roadmap](DATEIEN-ROADMAP.md).
- Eine Testinstallation mit Beispieldaten, Browser-Smoke-Test und aktuelle Screenshots erzeugt der Workflow **E2E Screenshots**; siehe [E2E-Test und Screenshots](SCREENSHOTS.md).
- Für Fork-Updates, Image-Veröffentlichungen und die dokumentierten Eingriffe in Upstream-Dateien siehe den [Fork-Leitfaden](../FORK.md).
