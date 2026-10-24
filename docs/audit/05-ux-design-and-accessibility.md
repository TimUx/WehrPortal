# UX, Design und Barrierefreiheit

> Historischer Audit des Vorgängerprojekts vom 20.10.2026. Befunde und Verweise beziehen sich auf den damaligen Snapshot, nicht auf eine aktuelle WehrPortal-Abnahme; siehe [Migration und Betrieb](../../MIGRATION.md).

## Befundlage

Es wurde keine Browserinstanz mit vollständigem Testkonto durchlaufen; daher gibt es keine bestätigten WCAG-, Kontrast-, Tastatur-, Screenreader-, Mobil- oder Touch-Befunde. Die Aussagen unten sind dokumentierte/statische Befunde und Prüfempfehlungen, keine simulierten Nutzertests.

### UX-01 — Aktiver Standort ist nicht durchgehend sichtbar

- **Schweregrad:** P2
- **Evidenz:** `docs-extended/LOCATIONS.md:45-46` dokumentiert, dass der Standortumschalter nur im Admin-Menü erscheint und für globale Admins primär den Zielstandort neuer Datensätze ändert.
- **Auswirkung:** Nutzer können in fachlichen Ansichten den aktiven Standortkontext möglicherweise nicht erkennen; das erhöht Bedienfehler bei standortbezogenen Schreibvorgängen. Sichtbarkeit in allen Ansichten wurde nicht zur Laufzeit bestätigt.
- **Prüfung:** Als Standortadmin, Modulrolle und globaler Admin auf Desktop und Mobilgerät Listen, Formulare, Dashboard, Exporte und neue Datensätze durchlaufen.
- **Vorschlag:** Aktiven Standort dauerhaft und barrierefrei in einer globalen Statusleiste anzeigen; bei gemeinsamem oder standortlosem Kontext diesen eindeutig benennen. Bestätigung vor standortübergreifenden Aktionen.
- **Akzeptanz:** Aktiver Scope ist in jeder datenverändernden Oberfläche sichtbar, programmatisch benannt und bleibt nach Seitenwechsel eindeutig.

## Barrierefreiheit und responsive Qualität

- In der fokussierten Suche wurden einzelne `aria-label`-Verwendungen gefunden; keine WCAG-/axe-Prüfung oder automatisierte A11y-CI-Stufe wurde nachgewiesen.
- Vollständige Tastaturbedienung, Fokusreihenfolge, Fokus-Rückgabe in Dialogen, Kontrast, Fehlermeldungszuordnung, Beschriftungen für Icon-only-Controls und Touch-Zielgrößen sind **ungeprüft**.
- Insbesondere Standortwechsel, Modulnavigation, Tabellen auf kleinen Viewports, Dateiablage, Kalender, Formulare mit Signatur/QR und Offline-Zustände benötigen einen realen Geräte-Walkthrough.

## Geplante UX-/A11y-Arbeit

| Priorität | Maßnahme | Abnahmekriterium |
|---|---|---|
| P2 | Standortstatus und globale Rollen-/Standortübersicht entwerfen. | Nutzer erkennt aktive Standorte, Modulrollen und Zugriffsgrenzen ohne Admin-Menü öffnen zu müssen. |
| P2 | Mobile Persona-Walkthroughs für Mitglied, Modulverantwortliche und Standortadmin durchführen. | Kernprozesse vollständig auf schmalem Viewport bedienbar; Lade-, Leer-, Fehler- und 403/404-Zustände verständlich. |
| P2 | Screenreader-/Tastaturprüfung kritischer Auth-, Rollen-, Datei- und Freigabeflows. | Alle Aktionen ohne Maus erreichbar; Dialogfokus sinnvoll; Status/Fehler programmatisch zugeordnet. |
| P3 | Automatisierte Accessibility-Prüfungen für repräsentative Seiten in E2E/CI ergänzen. | Keine bekannten kritischen/seriösen automatisierten A11y-Verstöße; manuelle Prüfung bleibt Release-Check. |
| P3 | UI-Regressionen für Standortwechsel, leere Berechtigungsmenüs, gesperrte Module und abgelaufene Links ergänzen. | Fehlende Berechtigung wird verständlich angezeigt und verborgene UI-Elemente ersetzen keine Serverautorisierung. |

## Design und Dokumentation

Die Dokumentation beschreibt eine modulare Navigation und listet Fork-Funktionen und Screenshots (`FORK.md:71-80`, `docs-extended/README.md`). Ein konsistenter visueller Design- oder Barrierefreiheits-Audit wurde nicht durchgeführt. Für künftig hinzugefügte Standortfunktionen sollten Texte, Statusfarben, Fokuszustände und mobile Darstellung dieselbe Semantik verwenden.
