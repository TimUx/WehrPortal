# Dateien – Roadmap nach Meilenstein 1

[Zur Hauptdokumentation](../README.md) · [Erweiterungsindex](README.md) · [Dateien-Funktionsbeschreibung](DATEIEN.md)

Planungsstand der Extension `extensions/Dateien/`, ausgehend von Meilenstein 1 (Dateiablage, Gruppenrechte, Versionierung). Meilensteine 2 bis 5 sind umgesetzt, Meilenstein 6 teilweise umgesetzt; Meilenstein 7 enthält spätere Ausbauideen. Die aktuelle Bedienung steht in [`DATEIEN.md`](DATEIEN.md). Dieses Dokument enthält keinen Code; neue Arbeit wird weiterhin separat umgesetzt.

## Inhalt

- [Ausgangslage nach M1 (historisch)](#ausgangslage-nach-m1-historisch)
- [Priorisierung](#priorisierung)
- [M2 – Betriebsreife (umgesetzt)](#m2--betriebsreife-must-have--umgesetzt)
- [M3 – Office-Grundlage und Ansicht (umgesetzt)](#m3--office-grundlage-und-ansicht-must-have-bei-office-go-live--umgesetzt)
- [M4 – Office-Bearbeitung (umgesetzt)](#m4--office-bearbeitung-must-have-bei-office-go-live--umgesetzt)
- [M5 – Öffentliche Freigaben (umgesetzt, optional)](#m5--öffentliche-freigaben-optional-standardmäßig-aus--umgesetzt)
- [M6 – Komfort und Zusammenarbeit (teilweise umgesetzt)](#m6--komfort-und-zusammenarbeit-optional--teilweise-umgesetzt)
- [M7 – Später / Ausbau (optional)](#m7--später--ausbau-optional)
- [Übergreifend pro Meilenstein](#übergreifend-pro-meilenstein)

## Ausgangslage nach M1 (historisch)

Die folgende Bestandsaufnahme beschreibt den Stand **nach M1 und vor den späteren Umsetzungen**. Ein Punkt unter „Lücken“ ist deshalb nicht automatisch ein heutiges Defizit; maßgeblich sind die Status der Meilensteine und die aktuelle [Funktionsbeschreibung](DATEIEN.md).

**Vorhanden:**
- Ablagen (`x_files_spaces`) mit Standort und Kontingent
- Datei-/Ordnerbaum mit Vererbungsgrenze und Papierkorb
- Rechte für Benutzer und Dateigruppen (`read`, `edit`, `manage`)
- Unveränderliche Versionen (PostgreSQL-Trigger)
- Auditprotokoll, nur Anhängen (`x_files_audit_events`)
- Persistente Löschliste (`x_files_pending_deletions`)
- Admin-Seite `/admin/dateien`, Explorer `/dateien`
- Modulrolle `gruppenverwalter`
- Standortgrenze über die Locations-Extension

**Lücken (aus dem Code abgeleitet):**
1. Nur Einzel-Upload, höchstens 50 MiB. `FileResponse::make()` erwartet die ganze Datei im Speicher; große Downloads skalieren nicht.
2. Der CHECK `source IN ('upload','restore')` in `x_files_versions` lässt kein Speichern aus Office zu.
3. Kein Abgleich Speicher ↔ Datenbank (verwaiste/fehlende Dateien); keine Aufbewahrungsregel für Audit-Einträge.
4. Die CSP erlaubt kein `frame-src`/`form-action` zu einem Office-Server; es gibt keine sitzungslosen Rückruf-Routen (WOPI, ONLYOFFICE-Callback).
5. Öffentliche Datei-URLs sind laut Konzept ausgeschlossen – nur als ausdrückliche, abschaltbare Ausnahme denkbar.

**Leitplanken für alle Meilensteine:**
- Core und Erweiterung werden gemeinsam in WehrPortal gepflegt; die frühere Fork-Beschränkung gilt nicht mehr. Eigene CSP-Header setzt die Antwort selbst; `SecurityHeaders` übernimmt einen vorhandenen Header.
- Rechte immer im Service prüfen (`FileAccess`), nicht nur in der Oberfläche.
- Migrationen laufen auf frischer und bestehender Datenbank; Trigger-Funktionen mit `CREATE OR REPLACE`.
- Geplante Befehle mit `appendOutputTo`.
- Tests unter `tests/Feature/Extensions/Dateien`, Larastan mit `extensions/Dateien/phpstan.neon`.

## Priorisierung

| Meilenstein | Einstufung |
|---|---|
| **M2 Betriebsreife** | **Must-have** für jeden produktiven Einsatz |
| **M3 Office-Grundlage und Ansicht** | **Must-have**, wenn Office zum Go-live gehört |
| **M4 Office-Bearbeitung** | **Must-have**, wenn Office zum Go-live gehört |
| M5 Öffentliche Freigaben | Optional, standardmäßig aus |
| M6 Komfort und Zusammenarbeit | Optional |
| M7 Später / Ausbau | Optional, nach Bedarf |

Reihenfolge: M2 → M3 → M4 → produktiver Go-live → M5 / M6 nach Bedarf → M7.

---

## M2 – Betriebsreife (Must-have) – umgesetzt

**Ziel:** Die Ablage läuft im Alltag ohne Datenverlust, ist wartbar und reicht für übliche Dateigrößen und Mengen.

**Arbeitspakete:**
1. **Downloads als Stream:** eigene Antwort, die vom Disk streamt, mit denselben Headern wie `FileResponse` (`private, no-store`, `nosniff`, Content-Type nach Endung, Disposition); `Range` für PDFs/Video; Prüfsumme beim Lesen optional kontrollieren.
2. **Uploads:** mehrere Dateien und Drag & Drop; optional Upload in Teilstücken (Limit über 50 MiB konfigurierbar); Kontingent vor und nach dem Upload prüfen (parallele Uploads); Fortschritt und Abbruch.
3. **Einstellungen in der Admin-Seite:** Uploadlimit, erlaubte Endungen (Teilmenge der `FileResponse`-Allowlist), Papierkorb-Aufbewahrung, Warnschwelle für Kontingente.
4. **Speicherabgleich:** Befehl `dateien:reconcile` (geplant, mit Report: verwaiste Schlüssel, fehlende Dateien, falsche Prüfsummen). Standardmäßig nur berichten, Löschen nach Bestätigung durch einen Admin. Löschliste automatisch abarbeiten (Retry mit Backoff).
5. **Backup/Restore:** dokumentiertes Verfahren für Datenbank **und** `storage/app/private/dateien`; Prüfbefehl für die Konsistenz nach einem Restore; persistentes Volume im Coolify-/Docker-Setup und in `README.md` dokumentieren.
6. **Audit:** Admin-Ansicht mit Filter (Ablage, Benutzer, Aktion, Zeitraum), CSV-Export über `App\Support\Csv::put()`; feste Aufbewahrung mit eigenem Prune-Pfad (Append-only-Trigger, analog Verbandbuch).
7. **Mitglieder-Lebenszyklus:** `MemberRemoval` mit Dateiversionen testen (`x_files_versions.created_by` ist `restrictOnDelete` und wird übertragen; Rechte fallen per Kaskade weg; `actor_id` im Audit bleibt). Verhalten für `left`/`inactive` festlegen und dokumentieren.
8. **Benachrichtigungen:** „Mit dir geteilt“ als `InApp`-Notification (Glocke + Push); Aktions-URL führt direkt zum Eintrag; keine Dateiinhalte.
9. **Suche nach Namen** in zugänglichen Ablagen, ACL-Filter in der Datenbank.
10. **Härtung:** `#[Locked]` an allen ID-Props im Explorer; Rate-Limit auf Upload und Download; `/sw.js` (Offline-Extension) cached `/dateien/*` nie.

**Risiken:**
- Datenbank und Dateisystem nach einem Restore nicht konsistent.
- PHP-/Proxy-Limits kleiner als das App-Limit.
- N+1 in `FileAccess::resolve` bei großen Bäumen → Vorfahren per rekursivem CTE laden.

**Abnahme:**
- 500-MiB-Download bei konstantem Speicherbedarf (falls Limit angehoben).
- Restore-Test (DB + Dateien) dokumentiert und erfolgreich.
- `dateien:reconcile` meldet absichtlich erzeugte verwaiste und fehlende Dateien.
- `MemberRemoval` funktioniert mit vorhandenen Versionen.
- Suche zeigt nur Einträge mit mindestens `read`.

**Testfokus:** Streaming-/Range-Antworten und Header; Kontingent bei parallelen Uploads; Abgleich und Löschliste mit simulierten Speicherfehlern; Rechte in der Suche (IDOR, Standort); `FreshInstallTest`; Notification ohne Dateiinhalte.

---

## M3 – Office-Grundlage und Ansicht (Must-have bei Office-Go-live) – umgesetzt

**Ziel:** DOCX/XLSX/PPTX, ODF und PDF öffnen im Browser über ONLYOFFICE **oder** Collabora, zunächst nur lesend.

**Vorab zu entscheiden:** erster Anbieter; Betrieb des Office-Servers (eigener Container oder extern); Erreichbarkeit App ↔ Office-Server; Lizenzgrenzen der Community-Editionen.

**Arbeitspakete:**
1. **Connector-Abstraktion** (`OfficeConnector`, Implementierungen ONLYOFFICE und WOPI/Collabora); höchstens ein aktiver Anbieter pro Instanz.
2. **Admin „Online-Office“** in `/admin/dateien`: Anbieter, Server-URL, interne Callback-URL der App; JWT-Secret bzw. WOPI-Einstellungen verschlüsselt gespeichert und im Audit maskiert; erlaubte Endungen je Aktion; „Verbindung testen“ (Discovery/Healthcheck). Die Server-URL ist eine Admin-Vorgabe; ob `SafeUrl` (blockiert private IPs) dafür umgangen wird, bewusst entscheiden und dokumentieren – Ziel nur aus dieser Einstellung, ohne Redirects.
3. **Office-Sitzungen:** Tabelle `x_files_office_sessions` (Token gehasht, Benutzer, Knoten, Version, Recht, Ablauf, Widerruf). Rechte bei **jedem** Rückruf erneut über `FileAccess` prüfen.
4. **Routen ohne `auth`, nur mit Token:** ONLYOFFICE Download-URL und Callback (JWT); WOPI `CheckFileInfo`/`GetFile` mit Proof-Key-Prüfung; eigenes Rate-Limit, `NoIndex`, kein CSRF, strikte Tokenprüfung.
5. **Editor-Seite** `/dateien/{node}/office`: eigene CSP mit `frame-src` (Collabora zusätzlich `form-action`) auf den Office-Host; Token nicht in Logs oder `mail_logs`; Referrer-Policy beachten.
6. **Audit** von Öffnen und Sitzungsende, ohne Tokens.

**Risiken:** Netzwerktopologie (Callback-URL ≠ `APP_URL`); Token-Leck über URL/Referrer; CSP nur Report-only; Lizenz-/Verbindungsgrenzen.

**Abnahme:**
- Beide Anbieter öffnen DOCX, XLSX, PPTX, ODT und PDF lesend.
- Abgelaufene, widerrufene, fremde Tokens und falsche Signaturen → 401/403.
- Nach Rechteentzug schlägt der nächste Rückruf fehl.
- Modul aus → 404.

**Testfokus:** manipulierte JWTs/Proof-Keys; Token für anderen Knoten/andere Version (IDOR); Standortgrenze bei Rückrufen; CSP der Editor-Seite; kein Token im Activity-/Mail-Log; Office-Server über `Http::fake`.

---

## M4 – Office-Bearbeitung (Must-have bei Office-Go-live) – umgesetzt

**Ziel:** Benutzer mit `edit` bearbeiten Dokumente im Browser, auch gleichzeitig; jede Speicherung ergibt eine saubere Version.

**Arbeitspakete:**
1. **Migration:** CHECK der Versionsquelle um `office` erweitern, `office_session_id` und `autosave` ergänzen, Sperrtabelle `x_files_office_locks`.
2. **Speichern:** ONLYOFFICE-Callback (Status 2/6) – Download nur vom konfigurierten Host, Größe/Typ prüfen, dann `FileService` → neue Version. WOPI `PutFile` – Lock prüfen, dann neue Version.
3. **Sperren/Konflikte:** WOPI `Lock`, `Unlock`, `RefreshLock`, `GetLock`; Upload einer neuen Version während offener Office-Sitzung wird abgelehnt bzw. als Konflikt gemeldet (erwartete Versionsnummer aus M1); `PutRelativeFile` und Umbenennen aus Office zunächst deaktiviert.
4. **Versionsstrategie:** Autosaves einer Sitzung zusammenfassen (eine Version pro Sitzung bzw. Forcesave).
5. **Aufräumen:** geplanter Befehl schließt hängende Sitzungen und abgelaufene Locks.
6. *Optional:* leere DOCX/XLSX/PPTX aus Vorlagen anlegen (noch offen).

**Risiken:** Versionsflut durch Autosave; Race Callback ↔ manueller Upload; SSRF über die Callback-Download-URL; Datenverlust bei fehlgeschlagenem Callback (loggen, Admin-Hinweis, Retry durch den Office-Server zulassen).

**Abnahme:**
- Zwei Benutzer bearbeiten gleichzeitig → genau eine konsistente neue Version mit Prüfsumme.
- `read` öffnet nur lesend.
- Callback-URL zu fremdem Host wird abgelehnt.
- Kontingent gilt auch für Office-Speicherungen.

**Testfokus:** Callback-/WOPI-Status-Matrix; Lock-Semantik (409 mit `X-WOPI-Lock`); gleichzeitiger Upload und Office-Speicherung; Kontingentüberschreitung; Rechteentzug während einer Sitzung; Versions-Trigger unverändert.

---

## M5 – Öffentliche Freigaben (optional, standardmäßig aus) – umgesetzt

Umsetzung siehe [`DATEIEN.md`](DATEIEN.md#öffentliche-links-meilenstein-5); der Upload-Briefkasten (Punkt 5) bleibt bei M7.

**Ziel:** Einzelne Dateien/Ordner kontrolliert per Link an Externe geben (z. B. Gemeinde, Nachbarwehr).

**Arbeitspakete:**
1. Globaler Admin-Schalter **und** Freigabe pro Ablage; ohne beides kein Link.
2. Tabelle `x_files_shares`: Token gehasht (wie Magic Links); Ablaufdatum Pflicht, Höchstdauer konfigurierbar; optionales Passwort (gehasht) und Download-Limit; nur lesend; Ersteller braucht `manage`.
3. Öffentliche Seite: nur der freigegebene Teilbaum, keine Eltern-/Geschwisternamen; `NoIndex`, strenges Rate-Limit, Brute-Force-Schutz beim Passwort; kein Office-Bearbeiten.
4. Widerruf, Admin-Liste aller aktiven Links, Audit jedes Zugriffs (IP gekürzt).
5. Upload-Briefkasten für Externe erst mit Virenscanner (M7).

**Risiken:** Datenabfluss durch weitergeleitete Links; Abweichung von „keine öffentlichen Datei-URLs“ – in `SECURITY.md` begründen; Standortdaten versehentlich öffentlich.

**Abnahme:** abgelaufene/widerrufene/erschöpfte Links → 404; Inhalte im Papierkorb nicht erreichbar; Feature aus → alle Links sofort wirkungslos.

**Testfokus:** Tokenraten und Rate-Limit; Pfad-Traversal und IDOR im Teilbaum; Header (`no-store`, `X-Robots-Tag`); Passwort-Brute-Force.

---

## M6 – Komfort und Zusammenarbeit (optional) – teilweise umgesetzt

Umgesetzt (siehe [`DATEIEN.md`](DATEIEN.md#komfort-und-zusammenarbeit-meilenstein-6)): Favoriten, „Zuletzt verwendet“, Startseiten-Karte, Bildvorschau, Ordner als ZIP, Beschreibung, Kommentare, Abos und Volltextsuche über Name und Beschreibung. **Offen:** PDF-Vorschau (bräuchte Ghostscript/Imagick im Image), Volltext über Dateiinhalte (Textextraktion), Verschieben zwischen Ablagen und Registry für andere Module.

- Favoriten, „Zuletzt verwendet“, Startseiten-Karte über `DashboardRegistry`.
- Vorschaubilder für Bilder/PDFs, serverseitig erzeugt, nicht öffentlich gespeichert.
- Ordner als ZIP (Stream, ACL pro Eintrag).
- Kommentare/Beschreibung an Dateien; Abos „bei Änderungen benachrichtigen“.
- Volltextsuche (PostgreSQL `tsvector`) mit ACL-Filter.
- Anbindung anderer Module über eine Registry (z. B. Dokument im Einsatzbericht verknüpfen), ohne interne Klassen zu importieren.

Verschieben zwischen Ablagen ist weiterhin **nicht umgesetzt**; beim späteren Ausbau müssten Kontingent und Rechte neu berechnet werden.

**Risiken:** Vorschau-Parser als Angriffsfläche; Leistung. **Testfokus:** ACL bei Vorschau, ZIP, Volltext; Speicherbedarf beim ZIP-Stream.

---

## M7 – Später / Ausbau (optional)

- Virenscanner (ClamAV/`clamd`) vor dem Veröffentlichen eines Uploads – **Pflicht**, sobald Externe hochladen.
- S3-kompatibler Speicher (atomare Veröffentlichung neu bewerten).
- WebDAV/Desktop-Sync (eigenes Authentifizierungskonzept, hoher Aufwand).
- Verschlüsselung der Dateien im Ruhezustand.
- Aufbewahrungsregeln und Legal Hold pro Ablage.
- Offline-Verfügbarkeit ausgewählter Dateien (nur nach Risikoabwägung).

---

## Übergreifend pro Meilenstein

- **Doku:** `docs-extended/DATEIEN.md` und bei Betriebsänderungen `README.md` fortschreiben; Betriebsanleitung Office-Server (Compose-Beispiel, Netzwerk, TLS).
- **CI:** Pest parallel, Larastan Level 5 ohne neue Baseline-Einträge, `composer audit`, `npm audit`.
- **Release:** pro Meilenstein ein Release mit Migrationstest auf einer Kopie der Produktivdatenbank; vorher Backup von Datenbank **und** Dateien.
- **Testdaten:** nur erfundene Personen und Domains (`ff-musterstadt.de`).
