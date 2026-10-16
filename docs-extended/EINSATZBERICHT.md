# Einsatzbericht: Analyse und Ergänzungen (`extensions/EinsatzberichtPlus`)

## Abgleich mit der FW-App

| Anforderung | Stand im Original | Ergebnis |
|---|---|---|
| Fahrzeugauswahl | vorhanden (aktive Fahrzeuge, standortgefiltert über `LocationScope`) | unverändert |
| Besatzung je Fahrzeug nach Personal und Standort | vorhanden (aktive Kameraden, standortgefiltert; Funktion je Person) | unverändert |
| Atemschutz | vorhanden als optionales Häkchen je Person, nicht verpflichtend | unverändert |
| Plätze müssen nicht alle besetzt sein | vorhanden (keine Pflichtplätze) | unverändert |
| Sollbesatzung beachten | fehlte (`fv_profiles.crew` wurde nicht ausgewertet) | **neu:** Hinweis unter-/überbesetzt, keine Sperre |
| Beteiligte Personen / Dritte | nur Freitext (`affected_parties`, `other_parties`) | **neu:** strukturiert, Rolle, Kontakt, verschlüsselt |
| Kostenpflichtig | nur bei Fehlalarm („Kostenersatz prüfen“) | **neu:** allgemeines Kennzeichen mit Kostenträger/Bemerkung |
| Verdienstausfall | fehlte | **neu:** je Einsatzkraft, Arbeitgeber, Stunden, Stand |
| Externes/Freitext-Fahrzeug | Datenmodell möglich (`vehicle_id` leer), aber keine Eingabe | **neu:** Eingabe „Externes Fahrzeug“ |
| Bereitschaft, Zeiten, Einsatzart, Lage, Material, Abschluss/Freigabe | vorhanden | unverändert |

## Umsetzung

- Eigene Tabellen `x_ebp_extras`, `x_ebp_persons`, `x_ebp_earning_losses` (Personendaten mit `encrypted`-Cast); keine Upstream-Tabelle wird geändert.
- Livewire-Komponente `ebp.extras`, eingebunden über `@includeIf('ebp::partials.editor')` im Editor (einziger Upstream-Eingriff, siehe `FORK.md`).
- Rechte wie der Editor: nur Einsatzleiter/Wehrführung/Admins; Bearbeiten nur bei `ReportService::canEdit`. Der Bericht wird über das standortgefilterte Modell geladen, andere Standorte sind 404.
- Sollbesatzung: `CrewSize` liest `fv_profiles.crew` (`1/8` → 9, `1/5/6` → 6) nur, wenn das Modul Fahrzeuge aktiv ist; ohne Eintrag kein Hinweis. Reiner Hinweis, nichts wird blockiert.
- Verdienstausfall nur für Besatzung des Berichts; wird ein Kamerad gelöscht, entfallen seine Einträge.
- Grenzen: die Zusatzangaben erscheinen im Editor, noch nicht im Druck oder in der Statistik; Aktivitätslog erfasst `Extensions\`-Modelle nicht (bewusst, Daten Dritter).
