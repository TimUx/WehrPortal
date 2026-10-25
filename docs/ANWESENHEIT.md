# Anwesenheitslisten (Erweiterung `extensions/Anwesenheit`)

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md)

Eigenständiges Modul für Übungen, Dienste und Besprechungen. Eine Liste braucht **keinen** Kalendertermin; Vorlagen und Kalendertermine sind nur eine optionale Vorbelegung (Titel, Art, Ort, Zeiten), danach ist die Liste ein eigener Datensatz.

![Screenshot: Anwesenheit – Listen](screenshots/anwesenheit/listen.png)

## Inhalt

- [Funktionen](#funktionen)
- [Rechte und Standorte](#rechte-und-standorte)
- [Offline](#offline)
- [Technik](#technik)

## Funktionen

- Übungsleiter (Auswahl), Teilnehmer (Mehrfachauswahl, nur Kameraden des Standorts), Beginn/Ende mit automatischer Dauer und Teilnehmerzahl.
- Dateianhänge (max. 5 × 5 MB, Dateitypen wie überall über `FileResponse::allowedExtensions()`), Auslieferung nur über `FileResponse`.
- PDF (dompdf) und E-Mail mit PDF und Anhängen. Empfänger: Adressen je Liste, sonst die instanzweiten Standard-Empfänger (Admin-Bereich → Anwesenheit → Einstellungen). Nur globale Admins ändern diesen Standard; Verwalter sehen ihn schreibgeschützt. Mailfehler werden gemeldet, nie geworfen.
- Status: Entwurf → Abgegeben. Ersteller bearbeiten Entwürfe; Verwalter bearbeiten auch abgegebene Listen.
- Stundennachweis (`HoursRegistry`) und Jahresbericht (`AnnualReportRegistry`) zählen nur abgegebene Listen.
- Vorlagen verwalten: Filament-Ressource „Vorlagen“ (`/admin/anwesenheit/vorlagen`).

## Rechte und Standorte

| Rolle | Darf |
|---|---|
| Kamerad mit Modulzugang | eigene Listen anlegen, Entwürfe bearbeiten, PDF/Mail |
| Modulrolle `verwalter` | Listen und Vorlagen der zugewiesenen Standorte; Einstellungen schreibgeschützt |
| Standort-Admin | Listen und Vorlagen nur der Standorte, an denen die Person Admin ist; Einstellungen schreibgeschützt |
| Globaler Admin | alle Daten aller Standorte, einschließlich instanzweiter Einstellungen |

Listen und Vorlagen tragen `ext_location_id` und nutzen `LocationScope`/`AssignsLocation` der Locations-Erweiterung (nur aktiv bei `locations.enabled`). Teilnehmer und Leitung kommen aus dem Standort der Liste. Kalender-Vorbelegung wird nur angeboten, wenn das Kalender-Modul aktiv ist (Prüfung per `class_exists` und Modulstatus, ohne harte Kopplung).

**Optionale standortbezogene Rollen:** Nach ausdrücklicher Aktivierung mit `LOCATIONS_SCOPED_ROLE_MODULES=anwesenheit` gilt für Nicht-Admins ausschließlich eine gültige Standort-Modulrolle `verwalter` am Standort der Liste beziehungsweise Vorlage. Globale Altrollen und die bloße Standort-Adminrolle reichen dann nicht mehr zur fachlichen Verwaltung. Der globale Admin vergibt diese Rollen in der Standortverwaltung; alle Einzelheiten und die notwendige Vorbereitung stehen unter [Locations](LOCATIONS.md#standortbezogene-modulrollen-gestufte-umstellung). Ohne Aktivierung bleibt das bisherige Rollenmodell erhalten. Globale Admins behalten in beiden Modi alle Datenzugriffe.

Bei mehreren Standortmitgliedschaften werden Verwaltungsrechte je Zielstandort geprüft. Leitung und Teilnehmende dürfen ihre Listen lesen, aber nicht allein deshalb bearbeiten, löschen oder erneut versenden. Ersteller bearbeiten und löschen eigene Entwürfe und dürfen eigene abgegebene Listen erneut versenden. Jeder dieser Zugriffe setzt weiterhin Standort- und Modulzugang voraus; die Prüfung erfolgt auch bei nachfolgenden Livewire-Aktionen. Listen ohne Standort sind bei aktiver Locations-Erweiterung nur globalen Admins zugänglich. Ein nachträglicher Standortwechsel einer Liste erfordert Verwaltungsrechte am Zielstandort.

Das Löschen einer Liste entfernt auch ihre zugehörigen Datei-Anhänge. Eine Vorlage ist nur eine Vorbelegung; Änderungen daran verändern bereits angelegte Listen nicht.

**Standortloser Altbestand:** Bei aktiver Locations-Erweiterung bleiben Listen und Vorlagen ohne Standort globalen Admins zur Prüfung vorbehalten; standortlose Vorlagen werden normalen Nutzern auch nicht als Vorbelegung angeboten. Der kontrollierte Betreiberbefehl `locations:assign-attendance` prüft ausdrücklich vorgeschlagene Zuordnungen zunächst ohne Schreibzugriff. Die Übernahme verlangt eine aktuelle Vorschau, einen aktiven globalen Admin, einen Grund und die Backup-Bestätigung. Sie verändert keine Teilnahmen oder Anhänge und versendet keine E-Mails. Ablauf, Konflikte und Rückfallgrenzen stehen unter [Bestandszuordnung Anwesenheit](LOCATIONS.md#bestandszuordnung-anwesenheit-p3-erster-datenbereich).

## Offline

Das Formular nutzt die Offline-Erweiterung (`data-offline-draft="anwesenheit-list"`, Felder mit `data-offline-draft-field`): Titel, Art, Zeiten, Leitung, Gästezahl und die Teilnehmendenauswahl werden lokal gesichert, können wiederhergestellt oder verworfen werden und werden beim Absenden gelöscht. Standortwahl, Notizen, Empfänger und Dateien werden nicht lokal gesichert. Es werden keine Livewire-Requests nachgespielt; siehe [`OFFLINE.md`](OFFLINE.md).

## Technik

Tabellen `x_att_*`, Livewire-Komponenten `anwesenheit.*`, alle Änderungen über `AttendanceService`. Abschaltbar mit `ANWESENHEIT_ENABLED=false`; das Modul steht nach der Migration inaktiv in der Modulliste und wird dort eingeschaltet.
