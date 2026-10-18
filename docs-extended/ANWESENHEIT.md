# Anwesenheitslisten (Erweiterung `extensions/Anwesenheit`)

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md) · [Fork-Leitfaden](../FORK.md)

Eigenständiges Modul für Übungen, Dienste und Besprechungen. Eine Liste braucht **keinen** Kalendertermin; Vorlagen und Kalendertermine sind nur eine optionale Vorbelegung (Titel, Art, Ort, Zeiten), danach ist die Liste ein eigener Datensatz.

## Inhalt

- [Funktionen](#funktionen)
- [Rechte und Standorte](#rechte-und-standorte)
- [Offline](#offline)
- [Technik](#technik)

## Funktionen

- Übungsleiter (Auswahl), Teilnehmer (Mehrfachauswahl, nur Kameraden des Standorts), Beginn/Ende mit automatischer Dauer und Teilnehmerzahl.
- Dateianhänge (max. 5 × 5 MB, Dateitypen wie überall über `FileResponse::allowedExtensions()`), Auslieferung nur über `FileResponse`.
- PDF (dompdf) und E-Mail mit PDF und Anhängen. Empfänger: Einstellung „Empfänger“ (Admin-Bereich → Anwesenheit → Einstellungen) plus Adressen je Liste. Mailfehler werden gemeldet, nie geworfen.
- Status: Entwurf → Abgegeben. Ersteller bearbeiten Entwürfe; Verwalter bearbeiten auch abgegebene Listen.
- Stundennachweis (`HoursRegistry`) und Jahresbericht (`AnnualReportRegistry`) zählen nur abgegebene Listen.
- Vorlagen verwalten: Filament-Ressource „Vorlagen“ (`/admin/anwesenheit/vorlagen`).

## Rechte und Standorte

| Rolle | Darf |
|---|---|
| Kamerad mit Modulzugang | eigene Listen anlegen, Entwürfe bearbeiten, PDF/Mail |
| Modulrolle `verwalter`, Standort-Admin | alle Listen **des eigenen Standorts**, Vorlagen, Einstellungen |
| Globaler Admin | alle Standorte |

Listen und Vorlagen tragen `ext_location_id` und nutzen `LocationScope`/`AssignsLocation` der Locations-Erweiterung (nur aktiv bei `locations.enabled`). Teilnehmer und Leitung kommen aus dem Standort der Liste. Kalender-Vorbelegung wird nur angeboten, wenn das Kalender-Modul aktiv ist (Prüfung per `class_exists` und Modulstatus, ohne harte Kopplung).

Das Löschen einer Liste entfernt auch ihre zugehörigen Datei-Anhänge. Eine Vorlage ist nur eine Vorbelegung; Änderungen daran verändern bereits angelegte Listen nicht.

## Offline

Das Formular nutzt die Offline-Erweiterung (`data-offline-draft="anwesenheit-list"`, Felder mit `data-offline-draft-field`): Titel, Art, Zeiten, Leitung, Gästezahl und die Teilnehmendenauswahl werden lokal gesichert, können wiederhergestellt oder verworfen werden und werden beim Absenden gelöscht. Standortwahl, Notizen, Empfänger und Dateien werden nicht lokal gesichert. Es werden keine Livewire-Requests nachgespielt; siehe [`OFFLINE.md`](OFFLINE.md).

## Technik

Tabellen `x_att_*`, Livewire-Komponenten `anwesenheit.*`, alle Änderungen über `AttendanceService`. Abschaltbar mit `ANWESENHEIT_ENABLED=false`; das Modul steht nach der Migration inaktiv in der Modulliste und wird dort eingeschaltet.
