# Dateien

Die Fork-Extension `extensions/Dateien/` ergänzt eine interne Dateiablage. Sie wird automatisch geladen, ist als Modul **standardmäßig ausgeschaltet** und benötigt keine Änderungen am Upstream.

## Einrichtung und Bedienung

1. Unter **Admin → Module** „Dateien“ einschalten und den gewünschten Mitgliedern freigeben. Die Freigabe allein gewährt noch keinen Zugriff auf eine Ablage.
2. Als globaler Admin unter **Admin → Dateien** (`/admin/dateien`) eine Ablage anlegen. Optional einen Standort und ein Kontingent in MiB wählen. Ohne Standort ist eine Ablage standortübergreifend; ein Kontingent umfasst **alle Versionen einschließlich Papierkorb**, nicht nur die aktuellen Inhalte.
3. Dort eigene Dateigruppen anlegen und bestehende Mitglieder zuordnen. Dateigruppen sind unabhängig von Abteilungen und Funktionen. Die Modulrolle `gruppenverwalter` erlaubt Gruppen- und Mitgliederverwaltung, aber keinen automatischen Zugriff auf Dateien. Das Gruppenverwalter-Kennzeichen einer Mitgliedschaft dokumentiert die Zuständigkeit; es ersetzt die Modulrolle nicht.
4. Unter `/dateien` die Ablage öffnen und Rechte an Benutzer oder Dateigruppen vergeben. Globale Admins dürfen in aktiven Modulen alles; für alle anderen gilt **standardmäßig kein Zugriff**.

Der Explorer bietet Ordner, Einzel-Upload, Umbenennen, Verschieben innerhalb einer Ablage, Downloads, Versionsverlauf, Rechte und Papierkorb. Namenskonflikte werden angezeigt; es gibt kein stilles Überschreiben. Für einen Inhaltswechsel ausdrücklich eine **neue Version** hochladen. „Mit mir geteilt“ erschließt Direktfreigaben, auch wenn der übergeordnete Ordner nicht zugänglich ist.

## Rechte

| Recht | Befugnisse |
|---|---|
| `read` – Lesen | Auflisten, ansehen, herunterladen, Versionsverlauf lesen |
| `edit` – Bearbeiten | Zusätzlich hochladen, Ordner erstellen, umbenennen, neue Versionen und Versionswiederherstellungen |
| `manage` – Verwalten | Zusätzlich Rechte/Vererbung ändern, verschieben, Papierkorb und Wiederherstellung, endgültig löschen |

Rechte gelten für eine ganze Ablage oder einen einzelnen Ordner/eine Datei. Benutzer- und Gruppenrechte werden zusammengeführt; das **höchste zutreffende Recht** gewinnt. Es gibt keine expliziten Verbote. Neue Einträge erben Rechte vom Elternordner beziehungsweise der Ablage. Wird die Vererbung an einem Eintrag unterbrochen, zählen dort nur dessen eigene Rechte und für dessen Nachkommen die Rechte unterhalb dieser Grenze.

Eine Direktfreigabe verrät keine Namen gesperrter Eltern oder Geschwister. Ein Ordnerrecht öffnet nicht automatisch Kinder mit unterbrochener Vererbung. Verschieben erfordert `manage` an Quelle **und** Ziel; es kann die geerbten Rechte verändern. Ablageübergreifendes Verschieben ist in diesem Meilenstein absichtlich ausgeschlossen.

### Standorte

Bei aktiver Locations-Extension prüft die zentrale Berechtigungslogik zusätzlich den Standort der Ablage. Auch ein direktes Benutzerrecht oder eine Gruppenmitgliedschaft umgeht diese Grenze nicht. Das gilt für technische Downloads und Service-Aufrufe, nicht nur für die Oberfläche. Gruppenverwalter sehen nur gemeinsame Gruppen und Gruppen ihrer Standorte und dürfen keine fremden Standortmitglieder durch manipulierte IDs hinzufügen. Globale Admins verwalten alle Standorte. Standortlose Ablagen und Gruppen sind bewusst gemeinsam – für vertrauliche Standortdaten immer einen Standort setzen.

## Versionierung und Papierkorb

- Jede neue Datei beginnt mit Version 1. Inhaltsänderungen hängen eine neue Version an; bestehende Versionsmetadaten sind durch einen PostgreSQL-Trigger gegen Updates geschützt.
- Eine alte Version wiederherzustellen erzeugt eine neue aktuelle Version. Der Verlauf wird nicht zurückgesetzt.
- Jede Version trägt SHA-256-Prüfsumme, Bytezahl, erkannten MIME-Typ, Endung, Ersteller und Herkunft. Identische erneute Verarbeitung des aktuellen Inhalts erzeugt keine Doppelversion.
- Änderungen an einer Ablage werden innerhalb einer Datenbanktransaktion gesperrt. Versionsaktionen unterstützen zusätzlich eine erwartete Versionsnummer: Ist der Stand veraltet, wird die Änderung mit einem Konflikt abgelehnt statt fremde Änderungen unbemerkt zu ersetzen.
- Löschen verschiebt in den Papierkorb. Inhalte unter einem gelöschten Ordner sind nicht mehr lesbar, auch nicht über frühere Direktfreigaben. Vor der Wiederherstellung muss der Elternordner wieder verfügbar sein; ein inzwischen belegter Name verhindert die Wiederherstellung.
- Endgültiges Löschen ist eine eigene bestätigungspflichtige Aktion mit `manage`. Die konfigurierbare Mindestaufbewahrung (Standard: 30 Tage) muss abgelaufen sein. **Es gibt keine automatische Löschung**.

## Datenmodell

Alle Tabellen verwenden `x_files_`:

| Tabelle | Inhalt |
|---|---|
| `spaces` | Ablagen, optionaler Standort, Kontingent |
| `nodes` | Datei-/Ordnerbaum, Vererbungsgrenze, Papierkorb |
| `versions` | Unveränderliche Versionsmetadaten und privater Speicherschlüssel |
| `groups`, `group_members` | Dateigruppen und bestehende Benutzer mit Zuständigkeitskennzeichen |
| `permissions` | Benutzer-/Gruppenrechte auf Ablagen oder Einträgen |
| `audit_events` | Aktionen und IDs, ohne Dateibytes oder Speicherzugangsdaten |

Fremdschlüssel, Prüfungen und eindeutige aktive Namen pro Ordner sichern die Struktur zusätzlich zur zentralen Service-Schicht. Favoriten sind nicht Teil dieses Meilensteins.

## Betrieb und Sicherheit

- Dateien liegen auf dem privaten Laravel-Disk `dateien` unter **`storage/app/private/dateien`**, niemals unter `public/` oder dem Storage-Symlink. Die Pfade bestehen aus generierten Schlüsseln, nicht aus eingegebenen Dateinamen.
- Dieses Verzeichnis muss persistent, für den PHP-Prozess schreibbar und auf allen App-Instanzen konsistent verfügbar sein. Standard ist ein lokales Dateisystem mit atomarem Umbenennen. Andere Storage-Treiber sind in diesem Meilenstein nicht unterstützt.
- **Datenbank und Dateiablage zusammen sichern und zusammen wiederherstellen.** Ein Datenbankbackup allein enthält keine Datei-Inhalte. Beim Backup Schreibzugriffe anhalten oder einen konsistenten gemeinsamen Snapshot erstellen.
- Uploads durchlaufen temporäres Schreiben, Typ-/Größenprüfung und atomare Veröffentlichung. Normale Fehler räumen temporäre beziehungsweise noch nicht referenzierte neue Inhalte auf. Ein harter Prozess-/Hostabbruch kann verwaiste Dateien hinterlassen: Speicher überwachen; unbekannte Dateien niemals allein anhand ihres Alters ohne Abgleich mit `x_files_versions` löschen.
- Endgültige Löschungen verwenden eine persistente Löschliste (`x_files_pending_deletions`): Erst nach erfolgreichem Datenbank-Commit werden Dateibytes entfernt. Bei Speicherfehlern bleiben die Aufträge erhalten. Globale Admins können unter **Admin → Dateien → Speicherbereinigung** ausstehende Löschungen erneut versuchen; weitere endgültige Löschaktionen arbeiten die Liste ebenfalls ab.
- Uploadlimit standardmäßig 20 MiB, administrativ bis maximal 50 MiB. Erlaubt sind nur ausgewählte Endungen aus der zentralen `FileResponse`-Allowlist und passende MIME-Typen. PHP (`upload_max_filesize`, `post_max_size`), Livewire-Temporärupload und Reverse Proxy können kleinere Grenzen setzen und müssen zum gewünschten Limit passen.
- Office-Dateien werden nur gespeichert/heruntergeladen, nicht serverseitig ausgeführt. Die Typprüfung ersetzt **keinen Virenscanner**. Dateien nur aus vertrauenswürdigen Quellen öffnen; ein Virenscanner ist nicht integriert.
- Downloads benötigen Anmeldung, aktives Konto, Modulzugriff, Standortzugriff und ACL. Versions-IDs werden an die angefragte Datei gebunden. Sichere Dateiauslieferung erfolgt über `FileResponse` mit `private, no-store` und `nosniff`; es gibt keine öffentlichen Datei-URLs.
- Datei-Inhalte, Tokens und Speicherzugangsdaten gehören nicht in Logs. Die Extension führt eigene Metadaten-Auditereignisse; der allgemeine ActivityRecorder verarbeitet keine Extension-Modelle.
- Die Dateien-Oberfläche und Downloads sind kein Offline-Angebot. Keine Dateien in Service-Worker- oder Proxy-Caches aufnehmen.

## Tests und Grenzen

Pest-Featuretests liegen unter `tests/Feature/Extensions/Dateien/` und benötigen PostgreSQL wie die übrigen Featuretests:

```bash
./vendor/bin/pest tests/Feature/Extensions/Dateien
./vendor/bin/phpstan analyse -c extensions/Dateien/phpstan.neon --memory-limit=2G
```

Geprüft werden Modulfreigabe, Benutzer-/Gruppenrechte, Vererbungsgrenzen, Direktfreigaben ohne Metadatenlecks, Versionierung und Wiederherstellung, Namens-/Versionskonflikte, Papierkorb, IDOR-Schutz und Standortgrenzen.

Nicht enthalten: Volltextindex, WebDAV, Desktop-Sync, Offline-Bearbeitung und Favoriten.

## Betrieb (Meilenstein 2)

- **Hochladen:** mehrere Dateien per Drag & Drop oder Auswahl, mit Fortschritt und Abbrechen (`POST /dateien/hochladen`, eine Datei pro Anfrage). Größenobergrenze der Admin-Einstellung: `DATEIEN_MAX_UPLOAD_LIMIT_KB` (Standard 51200). PHP (`upload_max_filesize`, `post_max_size`) und Reverse-Proxy müssen mindestens so viel zulassen.
- **Herunterladen:** gestreamt mit Range-Anfragen; Prüfsumme wird vor der Auslieferung geprüft (abschaltbar). Begrenzung: 60 Uploads und 300 Downloads pro Minute und Kamerad (`config/dateien.php` → `rate_limits`).
- **Suche:** Namenssuche über alle Bereiche; nur Einträge, die der Kamerad lesen darf, nie Papierkorb.
- **Benachrichtigung:** neue oder geänderte Freigaben melden sich bei den Empfängern (Glocke, Mail, Push), nur Name und Recht.
- **Kontingent-Warnung** ab einstellbarem Prozentsatz (Standard 90 %).
- **Dateiprotokoll:** `/admin/dateien/protokoll` (nur Admins), Filter und CSV-Export. Aufbewahrung einstellbar (Standard 730 Tage, 0 = unbegrenzt); `dateien:prune-audit` löscht täglich, sonst bleibt das Protokoll unveränderbar.
- **Geplante Läufe:** `dateien:drain-deletions` (alle 15 Min., ausstehende physische Löschungen mit wachsendem Abstand), `dateien:prune-audit` (täglich), `dateien:reconcile` (wöchentlich, löscht nichts).
- **Sicherung/Wiederherstellung:** Datenbank und `storage/app/private/dateien` gemeinsam sichern (zuerst DB, dann Dateien). Nach einer Wiederherstellung `php artisan dateien:reconcile --verify` ausführen; Exit-Code ≠ 0 bei fehlenden, veränderten oder verwaisten Dateien. Verwaiste Dateien löscht nur ein Admin auf der Verwaltungsseite nach erneuter Prüfung.
- **Ausgeschiedene/inaktive Kameraden** verlieren sofort jeden Zugriff; ihre Freigaben bleiben bis zur Reaktivierung bestehen. „Löschen mit Übertragung“ überträgt ihre Versionen an den gewählten Kameraden; das Protokoll behält die ursprüngliche ID.

## Online-Office (Meilensteine 3 und 4)

- **Anbieter:** ONLYOFFICE Docs (ab 8.1, wegen PDF) **oder** Collabora Online (WOPI); höchstens einer je Instanz. Einstellung in `/admin/dateien` → „Online-Office“ (nur Admins).
- **Adressen:**
  - *Office-Server für den Browser* (z. B. `https://office.ff-musterstadt.de`): iframe, Skript, CSP.
  - *Interne Adresse* (optional, z. B. `http://onlyoffice` im Docker-Netz): Healthcheck bzw. `/hosting/discovery`.
  - *Rückruf-Adresse der App* (leer = `APP_URL`): so erreicht der Office-Server die App.
  - Bewusste Entscheidung: diese Admin-Adressen laufen **nicht** über `SafeUrl` (interne Ziele sind erlaubt); die App ruft nur genau diese Adressen auf, ohne Weiterleitungen, Timeout 5 s.
- **ONLYOFFICE:** JWT-Secret Pflicht (≥ 32 Zeichen, gleich `JWT_SECRET` des Document Servers), verschlüsselt gespeichert, im Aktivitätsprotokoll maskiert. Konfiguration, Dateiabruf und Rückruf sind HS256-signiert; andere Algorithmen werden abgelehnt.
- **Collabora:** Jeder WOPI-Aufruf (`CheckFileInfo`, `GetFile`) braucht eine gültige Proof-Signatur (Schlüssel aus Discovery, auch der alte Schlüssel, höchstens 20 Minuten alt). Discovery wird eine Stunde zwischengespeichert.
- **Office-Sitzungen** (`x_files_office_sessions`): zufälliges Token je Öffnen, nur als SHA-256 gespeichert, gebunden an Kamerad, Datei, Version und Anbieter, 8 Stunden gültig (`config/dateien.php` → `office.session_minutes`). Bei **jedem** Aufruf des Office-Servers werden Token, Ablauf, Widerruf, Datei und die aktuellen Rechte (inkl. Standort, Status, Modulzugang) erneut geprüft. Antworten: 401 (Token/Signatur), 403 (Recht entzogen), 404 (Modul oder Office aus).
- **Routen ohne Login** unter `/dateien/office/…`: kein Session-Cookie, kein CSRF, eigenes Rate-Limit (600/Min. je IP), `NoIndex` global.
- **Editor-Seite** `/dateien/dateien/{id}/office`: eigene, **erzwungene** CSP (`default-src 'none'`, Skripte nur mit Nonce und vom Office-Host, `frame-src`/`form-action` nur Office-Host), `no-referrer` per Meta-Tag.
- **Protokoll:** `office.opened` (mit Stufe `read`/`edit`), `office.closed` (ONLYOFFICE Status 2/4, WOPI Unlock, untätig, abgelaufen, widerrufen), `office.revoked_all`, `office.settings_changed` – nie Tokens oder Secrets.
- **Widerruf:** Knopf „Alle Office-Sitzungen beenden“; Wechsel von Anbieter, Server-Adresse oder Secret beendet alle Sitzungen automatisch. `dateien:prune-office-sessions` (stündlich) räumt abgelaufene Sitzungen auf.
- **Bearbeiten (M4):**
  - Knopf „Im Office bearbeiten“ nur mit Recht `edit` und nur für Formate, die beide Server im selben Format zurückspeichern: `docx`, `odt`, `txt`, `xlsx`, `ods`, `csv`, `pptx`, `odp` (alte Binärformate und PDF nur ansehen). Admin-Schalter „Bearbeiten im Office erlauben“ (Standard an); aus = Speichern wird abgelehnt.
  - Sitzungen mit Stufe `edit` arbeiten immer auf der neuesten Version; jede Speicherung prüft die **aktuellen** Bearbeitungsrechte und das Speicherlimit und wird eine Version mit Quelle `office` (Ersteller = Kamerad der Sitzung).
  - **ONLYOFFICE:** Alle, die dieselbe Version öffnen, bearbeiten gemeinsam (gleicher `document.key`). Rückruf Status 2 (alle geschlossen) und 6 (Speichern-Knopf/Forcesave) speichern; der Download erfolgt **nur** vom eingestellten Office-Server (Browser- oder interne Adresse; die Browser-Adresse wird intern abgerufen), ohne Weiterleitungen, höchstens bis zum Upload-Limit, Timeout `office.save_timeout` (60 s). Es zählt nur der Inhalt des geprüften JWT; ein abweichender `filetype` oder `key` wird abgelehnt. Fehlschlag → `{"error":1}` (der Document Server behält das Dokument) und `office.save_failed`.
  - **Collabora (WOPI):** `Lock`, `GetLock`, `RefreshLock`, `Unlock` (30 Minuten, `x_files_office_locks`), `PutFile` nur mit der aktuellen Sperre, sonst 409 mit `X-WOPI-Lock`. Weicht `X-COOL-WOPI-Timestamp` von der neuesten Version ab, folgt 409 mit `COOLStatusCode 1010` (Collabora fragt dann nach). `PutRelativeFile` und `RenameFile` sind abgeschaltet (501). Schreiben nur mit einer Bearbeitungssitzung.
  - **Versionen:** Automatische Zwischenspeicherungen (Collabora `IsAutosave`, ONLYOFFICE Forcesave per Timer) ersetzen die vorige Zwischenspeicherung **derselben Sitzung**, solange sie die neueste Version ist; ausdrückliche Speicherungen und das Schließen bleiben eigene Versionen. Ersetzte Dateien gehen über die Lösch-Warteschlange.
  - **Sperre für Uploads:** Solange eine Bearbeitungssitzung aktiv ist (Aktivität innerhalb `office.edit_idle_minutes`, 60 Min.) oder eine WOPI-Sperre gilt, werden „Neue Version“ und „Wiederherstellen“ abgelehnt; der Explorer zeigt einen Hinweis. Wurde trotzdem außerhalb des Office gespeichert (nach Ablauf des Fensters), bleibt die Office-Speicherung eine weitere Version und `office.saved` vermerkt `conflict`.
  - `dateien:prune-office-sessions` beendet zusätzlich untätige Bearbeitungssitzungen (`office.closed`, Grund `idle`) und löscht abgelaufene Sperren.
  - **Protokoll:** `office.saved` (Version, Autosave, beteiligte Kameraden bei ONLYOFFICE), `office.save_failed` (Grund, ohne Inhalt).

## Öffentliche Links (Meilenstein 5)

Optional und **standardmäßig aus**. Damit gibt ein Verwalter eine Datei oder einen Ordner an Externe (Gemeinde, Nachbarwehr) zum Herunterladen frei, ohne Anmeldung.

- **Einschalten (Admin, `/admin/dateien` → „Öffentliche Links“):** globaler Schalter und Höchstdauer (1–365 Tage, Standard 30); zusätzlich jede Ablage einzeln „Erlauben“. Ohne beides entsteht kein Link; Ausschalten (global oder pro Ablage) macht alle bestehenden Links sofort wirkungslos. Dort steht auch die Liste aller aktiven Links mit Widerruf.
- **Erstellen (Explorer, Abschnitt „Öffentliche Links“):** nur mit Recht „Verwalten“ auf dem Eintrag. Pflicht: Gültigkeit in Tagen; optional Passwort (mind. 8 Zeichen), Download-Limit, Bezeichnung. Der Link wird **nur einmal** angezeigt (gespeichert ist nur der SHA-256-Hash des 48-Zeichen-Tokens). Verwalter sehen die aktiven Links des Eintrags und widerrufen sie.
- **Öffentliche Seite `/freigabe/{token}`:** zeigt nur den freigegebenen Teilbaum (Pfad ab der Freigabe, keine Namen darüber oder daneben), Name, Größe, Datum; Download der jeweils neuesten Version. Kein Office, keine Bearbeitung, keine älteren Versionen. Eigenständige Seite ohne App-Layout, eigene CSP (`default-src 'none'`), `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `noindex`; der Service Worker lässt `/freigabe/` aus.
- **Immer 404** (ohne Unterscheidung), wenn: Funktion aus, Modul aus, Ablage nicht freigegeben, Link abgelaufen, widerrufen oder Download-Limit erreicht, Eintrag oder ein übergeordneter Ordner im Papierkorb, Ziel außerhalb des Teilbaums (IDOR), Ersteller hat „Verwalten“ verloren oder ist nicht mehr aktiv.
- **Download-Limit:** wird atomar gezählt (`UPDATE … WHERE download_count < max_downloads`); öffentliche Downloads liefern nur ganze Dateien (`Range` wird ignoriert), damit das Limit nicht stückweise umgangen wird.
- **Schutz:** Rate-Limit pro Adresse (`dateien.shares.rate_limit`, 60/min) für Seiten und Downloads; Passwort 5 Fehlversuche pro Adresse und 30 pro Link in 15 Minuten, danach 429. Entsperren gilt für die Browsersitzung (Session wird erneuert).
- **Audit:** `share.created`, `share.revoked`, `share.downloaded`, `share.unlocked`, `share.password_failed`, `share.settings_changed`, `share.space_changed`; IP gekürzt (IPv4 /24, IPv6 /48), nie das Token.
- **Aufräumen:** `dateien:prune-shares` (täglich 03:30) löscht Links 30 Tage nach Ablauf oder Widerruf.
- **Grenzen:** Ein weitergeleiteter Link gilt für jeden, der ihn kennt – daher kurze Laufzeiten, Passwort über einen anderen Kanal, Download-Limit. Kein Upload für Externe (Briefkasten erst mit Virenscanner, M7).
