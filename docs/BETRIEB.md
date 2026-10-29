# Betrieb und Entwicklung

[Zur README](../README.md) · [Modulhandbücher](README.md)

## Betrieb und Entwicklung

### Docker-Images und Releases

Fertige Images werden als [GitHub Package `wehrportal`](https://github.com/TimUx/WehrPortal/pkgs/container/wehrportal) in der **GitHub Container Registry** veröffentlicht:

- **Release-Version:** `ghcr.io/timux/wehrportal:v0.9.5.5` (Beispiel; nur tatsächlich veröffentlichte Tags verwenden).
- **Letzter stabiler Release:** `ghcr.io/timux/wehrportal:latest`. Für Produktion einen festen Versions-Tag bevorzugen.
- **Auslöser:** Ein Versions-Tag wie `v0.9.5.5` oder ein in GitHub veröffentlichter Release startet den Release-Workflow. Der Tag muss zur Version in `config/app.php` passen. Nach Tests, Sicherheitsprüfungen und Image-Scan wird das Image veröffentlicht. Beim Tag-Push legt der Workflow anschließend den GitHub Release an; bei einem bereits veröffentlichten Release bleiben dessen Titel, Hinweise und Vorabrelease-Status erhalten. Vorabreleases aktualisieren `latest` nicht.
- **Keine Images bei normalen Commits:** Pushes und Pull Requests nach `main` prüfen den Code, veröffentlichen aber kein Image. Der bisherige Schalter `PUBLISH_IMAGES` ist nicht mehr nötig. Es gibt weiterhin kein automatisches Produktionsdeployment.

Die normale `docker-compose.yml` nutzt für App und Worker das Registry-Image. In `.env` legt `WEHRPORTAL_VERSION` die Version fest; ohne Angabe wird `v0.9.5.5` verwendet. Nach Einrichtung von Anwendungsschlüssel, Datenbankpasswort und Mailversand:

```bash
docker compose pull
docker compose up -d
```

Ein lokaler Build ist nur mit der zusätzlichen `docker-compose.build.yml` aktiv:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up --build -d
```

Nach der ersten Veröffentlichung das Package in GitHub auf **Public** stellen, damit es ohne Registry-Anmeldung geladen werden kann. Private Packages benötigen ein Login mit `read:packages`. Der Workflow verwendet `GITHUB_TOKEN` mit `packages: write`; ein zusätzliches Registry-Secret ist nicht erforderlich. [Compose-Einrichtung, persistente Daten und Updates](../handbuch/INSTALLATION.md#mit-docker-compose-starten).

### Tech-Stack

| Bereich    | Technologie                                          |
|------------|------------------------------------------------------|
| Backend    | PHP ^8.2 (Docker und CI: PHP 8.4), Laravel 12, Livewire 4 |
| Admin      | Filament 5                                           |
| Frontend   | Tailwind CSS 4, Alpine.js, FullCalendar 6, Vite      |
| Datenbank  | PostgreSQL (CI: 18, Docker Compose: 16; mit `btree_gist`) |
| Tests      | Pest 3                                               |
| Deployment | Docker Compose auf einem VPS, Docker-Image (serversideup/php), GitHub Actions |

### Installation

Der **[Installationsguide](../handbuch/INSTALLATION.md)** führt durch Voraussetzungen, Produktions- und Entwicklungsbetrieb, Abnahme, Sicherung und Updates. Die folgende Anleitung beschreibt den **Betrieb mit Docker Compose auf einem eigenen VPS**, unabhängig vom Hosting-Anbieter und ohne Verwaltungsplattform.

Benötigt werden ein Linux-VPS mit SSH-Zugang und Administratorrechten, eine Domain mit Zugriff auf die DNS-Einträge sowie ein SMTP-Postfach zum Versenden der Login-Mails. Grundkenntnisse im Umgang mit Linux, Docker und einem Reverse-Proxy sind erforderlich. Die Beispiele verwenden die erfundene Wehr „FF Musterstadt“ und `https://wehrportal.ff-musterstadt.de`.

#### 1. VPS vorbereiten

Mindestens **2 CPU-Kerne, 4 GB RAM und 50 GB Speicher** vorsehen; der tatsächliche Speicherbedarf hängt von Uploads und Sicherungen ab. Ein gepflegtes Linux-System verwenden, z. B. Ubuntu Server 24.04 LTS oder Debian. Den Standort nach den eigenen Datenschutzanforderungen wählen.

- Mit einem SSH-Schlüssel anmelden. Bei Bedarf auf dem eigenen Rechner mit `ssh-keygen -t ed25519` erzeugen und den **öffentlichen** Schlüssel beim Server hinterlegen.
- Betriebssystem aktuell halten und Sicherheitsupdates einrichten.
- In Server- und gegebenenfalls Anbieter-Firewall nur SSH (üblicherweise Port **22**, möglichst auf Verwaltungszugänge beschränkt), **80** und **443** freigeben. PostgreSQL und Redis nicht öffentlich erreichbar machen.
- **Docker Engine und das Docker-Compose-Plugin** nach der [offiziellen Anleitung für das verwendete Linux-System](https://docs.docker.com/engine/install/) installieren. Danach `docker version` und `docker compose version` prüfen.
- Docker beim Systemstart aktivieren. Die folgenden Docker-Befehle benötigen entsprechende Rechte; falls nötig `sudo` voranstellen. Mitgliedschaft in der Docker-Gruppe entspricht praktisch Root-Zugriff.

#### 2. Domain einrichten

Für `wehrportal.ff-musterstadt.de` einen **A-Eintrag** auf die öffentliche IPv4-Adresse des VPS setzen. Einen **AAAA-Eintrag** nur setzen, wenn IPv6 am Server einschließlich Firewall und Reverse-Proxy tatsächlich funktioniert. Eine weitere Verwaltungsdomain ist nicht nötig.

#### 3. Compose-Dateien bereitstellen

> **Image-Verfügbarkeit:** Der Release-Workflow veröffentlicht `ghcr.io/timux/wehrportal` nach erfolgreichen Prüfungen mit dem passenden Versions-Tag. Vor dem Deployment den erfolgreichen Lauf und das vorhandene [Package](https://github.com/TimUx/WehrPortal/pkgs/container/wehrportal) prüfen. Nach der ersten Veröffentlichung das Package für anonyme Downloads auf **Public** stellen. Falls bewusst lokal gebaut werden soll, die zusätzliche `docker-compose.build.yml` verwenden. Kein Workflow löst ein Deployment aus; keine Images anderer Repositories als Ersatz verwenden.

Einen tatsächlich veröffentlichten festen Tag aus den [WehrPortal-Releases](https://github.com/TimUx/WehrPortal/releases) wählen, z. B. `v0.9.5.5`. `latest` folgt dem letzten stabilen Release, ist aber nicht fest gepinnt. `docker-compose.yml` und `.env.example` **aus diesem Release** in ein eigenes Betriebsverzeichnis auf dem VPS herunterladen, beispielsweise:

```bash
mkdir -p ~/wehrportal
cd ~/wehrportal
curl -fSLo docker-compose.yml https://raw.githubusercontent.com/TimUx/WehrPortal/v0.9.5.5/docker-compose.yml
curl -fSLo .env.example https://raw.githubusercontent.com/TimUx/WehrPortal/v0.9.5.5/.env.example
cp .env.example .env
chmod 600 .env
```

Bei einer anderen Version beide Download-URLs und später `WEHRPORTAL_VERSION` entsprechend ändern. Die folgenden Compose-Befehle immer in diesem Betriebsverzeichnis ausführen.

Compose startet App, Worker, PostgreSQL und Redis im gemeinsamen internen Netz. Datenbank und Redis veröffentlichen keine Ports. Die benannten Volumes `pgdata`, `redisdata` und `app_storage` bleiben bei Containerwechseln erhalten. `app_storage` ist unter `/var/www/html/storage/app` eingebunden und enthält insbesondere `private/dateien`. **Nie `docker compose down -v` ausführen**, solange diese Daten gebraucht werden.

Für den hier beschriebenen Reverse-Proxy **auf dem VPS selbst** in `docker-compose.yml` beim Dienst `app` die Portfreigabe `"8080:8080"` durch **`"127.0.0.1:8080:8080"` ersetzen**. So ist die App nicht direkt aus dem Internet erreichbar; auf eine Firewall allein sollte man bei veröffentlichten Docker-Ports nicht vertrauen. Für automatischen Wiederanlauf nach einem Neustart bei allen vier Diensten auf derselben Ebene wie `image:` jeweils `restart: unless-stopped` ergänzen.

#### 4. WehrPortal konfigurieren

In `.env` die folgenden Werte setzen und alle Platzhalter ersetzen. Compose übernimmt die unterstützten Variablen und setzt Produktionsmodus, interne Datenbankverbindung und Cache-Konfiguration selbst:

```env
WEHRPORTAL_VERSION=v0.9.5.5
APP_NAME="FF Musterstadt"
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...
APP_URL=https://wehrportal.ff-musterstadt.de
DB_PASSWORD=<starkes zufälliges Datenbankpasswort>

MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=smtp.example.com
MAIL_PORT=465
MAIL_USERNAME=wehrportal@ff-musterstadt.de
MAIL_PASSWORD=<Passwort des Postfachs>
MAIL_FROM_ADDRESS="wehrportal@ff-musterstadt.de"
MAIL_FROM_NAME="FF Musterstadt"

ADMIN_EMAILS=wehrfuehrer@ff-musterstadt.de
DISPLAY_TOKEN=<zufälliger Schlüssel>
```

- **`APP_KEY`** schützt unter anderem gespeicherte Zugangsdaten und Fachdaten. Einmal erzeugen und **nie wieder ändern**: `echo "base64:$(openssl rand -base64 32)"`
- **`DB_PASSWORD`** z. B. mit `openssl rand -hex 32` erzeugen. Compose initialisiert PostgreSQL beim ersten Start mit diesem Passwort und richtet die Datenbank `wehrportal` ein. Ein später geänderter `.env`-Wert ändert das Passwort einer vorhandenen Datenbank nicht automatisch.
- **`DISPLAY_TOKEN`** ist der Schlüssel für den Wachen-Monitor ohne Login: `openssl rand -hex 24`. Leer lassen, wenn kein Monitor gebraucht wird.
- **E-Mail:** Ohne funktionierenden Mailversand kann sich niemand anmelden – die Login-Links kommen per Mail. Am einfachsten ist ein eigenes Postfach der Wehr beim Domain-Anbieter. Für Port 587 gilt `MAIL_SCHEME=smtp`, für Port 465 `smtps`. Zum Ausprobieren ohne Versand: `MAIL_MAILER=log` (die Mails stehen dann im Log der App).
- **`ADMIN_EMAILS`:** eine oder mehrere Adressen (mit Komma getrennt), die bei jedem Start als aktive Admins angelegt werden.

Geheimnisse nicht in Git speichern. `.env`, Compose-Datei und `APP_KEY` geschützt sichern. Zeitstempel speichert die App in UTC; angezeigt wird `Europe/Berlin`.

#### 5. Container starten

```bash
docker compose config --quiet
docker compose pull
docker compose up -d
docker compose ps
docker compose logs --tail=100 app
curl --fail http://127.0.0.1:8080/up
```

Die App legt beim ersten Start die Tabellen einschließlich der benötigten PostgreSQL-Erweiterung `btree_gist` an. Migrationen und Scheduler laufen im App-Container; für den synchronen Mailversand ist kein zusätzlicher Worker nötig. Der in Compose enthaltene Worker verarbeitet andere Queue-Aufgaben.

#### 6. HTTPS-Reverse-Proxy einrichten

Ein Reverse-Proxy nimmt öffentliche Anfragen auf Port **443** entgegen, verwaltet das TLS-Zertifikat und leitet intern an **`http://127.0.0.1:8080`** weiter. Die Compose-Datei stellt selbst **kein HTTPS** bereit. Geeignet sind beispielsweise Caddy, nginx oder Apache; Installation und Zertifikatserneuerung nach der Dokumentation des gewählten Proxys einrichten.

**Beispiel mit Caddy als Systemdienst direkt auf dem VPS:** Caddy nach der [offiziellen Anleitung](https://caddyserver.com/docs/install) installieren und in `/etc/caddy/Caddyfile` den folgenden Website-Block eintragen:

```caddyfile
wehrportal.ff-musterstadt.de {
    reverse_proxy 127.0.0.1:8080
}
```

Anschließend die Konfiguration prüfen und laden:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
curl --fail https://wehrportal.ff-musterstadt.de/up
```

Bei korrekt gesetztem DNS und erreichbaren Ports 80/443 beschafft und erneuert Caddy das Zertifikat automatisch und leitet HTTP auf HTTPS um. Es darf kein anderer Dienst dieselben öffentlichen Ports belegen. Bei einem anderen Proxy Hostname sowie `X-Forwarded-Proto` korrekt weitergeben. Die Adresse in `APP_URL` muss zur öffentlichen HTTPS-Adresse passen.

Ein Proxy **in einem Container** benötigt stattdessen ein gemeinsames Docker-Netz und das Ziel `app:8080`; `127.0.0.1` würde dort den Proxy-Container selbst meinen. Die obige Loopback-Portfreigabe und Caddy-Konfiguration gelten für den Proxy auf dem Host.

#### 7. Erste Schritte in WehrPortal

1. `https://wehrportal.ff-musterstadt.de/anmelden` öffnen, die Adresse aus `ADMIN_EMAILS` eingeben und den Link aus der Mail öffnen.
2. Oben rechts **Admin** → **Einstellungen**: Name und Kurzname der Wehr, Mail-Signatur und **Logo** hochladen.
3. **Kameraden** anlegen (oder per Import). Jeder Standort erhält automatisch die Abteilungen Einsatzabteilung, Jugendfeuerwehr, Kinderfeuerwehr, Alters- und Ehrenabteilung, Vorstand und Verein; weitere Abteilungen und Funktionen lassen sich ergänzen. Kameraden können mehreren Standorten und Abteilungen angehören und mehrere Funktionen gleichzeitig innehaben. Die Funktion „Vorstand“ (oder eine Unterfunktion davon) zählt weiterhin für die fachlichen Vereinsrechte; die Mitgliedschaft in der Abteilung Vorstand wird unabhängig davon manuell gepflegt.
4. **Module:** die gewünschten Module einschalten und über „x Kameraden“ freigeben oder „Neue Kameraden automatisch freischalten“ aktivieren. Rollen (z. B. Entscheider, Kassenwart) dort vergeben.
5. Im **Admin-Dashboard** unter „Verbindungen“ prüfen, ob der Mailversand funktioniert.
6. Den Kameraden die Adresse schicken. Sie melden sich selbst mit ihrer E-Mail-Adresse an.

#### 8. Updates einspielen

Updates kommen ausschließlich aus den [WehrPortal-Releases](https://github.com/TimUx/WehrPortal/releases) beziehungsweise dem eigenständigen `main`-Branch. Nur tatsächlich veröffentlichte Tags und Images verwenden; es gibt keinen automatischen Abgleich mit einem Basis-Repository.

1. Vor jedem Update einen konsistenten Sicherungsstand von Datenbank und App-Dateispeicher erstellen; Schreibzugriffe einschließlich automatisierter Änderungen währenddessen unterbinden. `APP_KEY` und Betriebs-/Umgebungskonfiguration getrennt geschützt aufbewahren. Ablauf: [Sichern und wiederherstellen](../handbuch/INSTALLATION.md#sichern-und-wiederherstellen).
2. In `.env` `WEHRPORTAL_VERSION` auf den tatsächlich veröffentlichten Tag im Format `v<Version>` setzen und `docker compose pull && docker compose up -d` ausführen. Bei geänderten Compose-Vorgaben die Release-Datei mit der eigenen Konfiguration abgleichen; Loopback-Portbindung, Neustartregeln und Volumes beibehalten.
3. Datenbank-Änderungen laufen beim Start automatisch. Die Version steht im Admin-Bereich oben klein hinter dem Titel.
4. `docker compose ps` und `docker compose logs --tail=100 app` prüfen, danach HTTPS-Health-Check, Anmeldung und Mailversand kontrollieren.

**Wie oft?** Mindestens **monatlich**, und sofort, wenn ein Release als **Sicherheitsupdate** gekennzeichnet ist. Wer „Watch → Custom → Releases“ beim Repository einschaltet, bekommt jede neue Version per Mail.

Ein alter Image-Tag dreht die Datenbankmigrationen nicht zurück. Benötigt die vorige Version den alten Datenstand, den passenden Datenbank- und Dateisicherungsstand aus Schritt 1 gemeinsam wiederherstellen und den ursprünglichen `APP_KEY` beibehalten. Details: [Sichern und wiederherstellen](../handbuch/INSTALLATION.md#sichern-und-wiederherstellen).

#### 9. Sicherungen im Blick behalten

| Was | Wo | Wie oft |
|---|---|---|
| Datenbank | PostgreSQL-Dump, z. B. mit `docker compose exec -T pgsql pg_dump -U wehrportal -d wehrportal -Fc > wehrportal.dump`; anschließend geschützt extern ablegen | täglich |
| App-Dateispeicher, insbesondere `storage/app/private/dateien` | persistentes App-Volume → geschützter externer Sicherungsspeicher, konsistent mit der Datenbank | täglich |
| `APP_KEY` und Betriebs-/Umgebungskonfiguration | getrennt geschützter Sicherungsspeicher | bei Einrichtung und jeder Änderung |
| Reverse-Proxy-Konfiguration und Sicherungsaufträge | geschützter externer Sicherungsspeicher | bei Einrichtung und jeder Änderung |
| ganzer Server (ergänzend) | unabhängige Server-Sicherung oder Snapshot beim gewählten Anbieter | nach eigenem Sicherungskonzept |

Der Datenbank-Dump allein sichert keine App-Dateien. Für einen konsistenten gemeinsamen Stand alle schreibenden App-/Worker-/Integrationsdienste während Datenbank- und Dateisicherung anhalten; PostgreSQL für den Dump weiterlaufen lassen. Sicherungen verschlüsseln, Erfolg und freien Speicher überwachen und mindestens vierteljährlich eine vollständige Wiederherstellung in einer getrennten Instanz erproben. Details: [Sichern und wiederherstellen](../handbuch/INSTALLATION.md#sichern-und-wiederherstellen).

### Konfiguration

Die technischen Basiseinstellungen laufen über Umgebungsvariablen. Beim Compose-Betrieb stehen die unterstützten Werte in `.env` im Betriebsverzeichnis; `docker-compose.yml` übergibt sie an die Container. Weitere Variablen müssen dort ausdrücklich unter `environment` ergänzt werden. Änderungen mit `docker compose up -d` übernehmen; ein bloßes `docker compose restart` lädt geänderte Umgebungsvariablen nicht neu. Wehrname, Logo und viele Moduleinstellungen werden dagegen in der Admin-Oberfläche gepflegt. Die wichtigsten Umgebungsvariablen:

| Variable                 | Beschreibung                                            |
|--------------------------|---------------------------------------------------------|
| `APP_URL`                | Öffentliche URL. Alle Links in Mails (Login, Einladung) werden daraus gebaut, nie aus dem Host der Anfrage. Mit `https://` sind die Session-Cookies automatisch `Secure`. |
| `DB_*`                   | Zugangsdaten für PostgreSQL                             |
| `WEHRPORTAL_VERSION`      | Von Docker Compose verwendeter Release-Tag aus GHCR; Standard `v0.9.5.5`. App und Worker verwenden dieselbe Version. |
| `MAIL_*`                 | SMTP-Server für alle Mails. Für Port 465 gilt `MAIL_SCHEME=smtps`. |
| `ADMIN_EMAILS`           | Kommagetrennte Adressen, die beim Container-Start als Admin angelegt bzw. aktiviert werden |
| `DISPLAY_TOKEN`          | Geheimer Schlüssel für den Wachen-Monitor ohne Login (`/monitor/<Schlüssel>`). Leer bedeutet nur für Admins. |
| `MAGIC_LINK_TTL_MINUTES` | Gültigkeit eines Login-Links in Minuten (Standard: 15)  |
| `SESSION_LIFETIME`       | Session-Dauer in Minuten (Standard: 90 Tage)            |
| `LOCATIONS_SCOPED_ROLE_MODULES` | Standardmäßig leer. `anwesenheit` und/oder `mitteilungen` aktivieren nach expliziter Rollenvergabe die jeweiligen Standortrollenadapter (kommagetrennt). Kein pauschaler Schalter für vollständige Mandantentrennung. |
| `LOG_CHANNEL`            | Im Docker-Image `stderr`, damit Fehler in den Container-Logs erscheinen |

Alle Mails werden sofort verschickt, ein Queue-Worker ist nicht nötig. Der Laravel-Scheduler läuft im Docker-Image als eigener Dienst neben nginx und PHP-FPM mit, ein separater Container oder Cronjob ist nicht nötig. Er verschickt jede Minute fällige Erinnerungsmails und räumt täglich abgelaufene Login-Tokens auf.

### Sicherheit

Wie WehrPortal gegen Lücken abgesichert wird und wie man eine meldet, steht in [SECURITY.md](../SECURITY.md). Kurz:

- **Updates:** Dependabot schlägt jede Woche Updates aller Abhängigkeiten vor (PHP, JavaScript, Docker-Basis-Image, GitHub-Actions); Sicherheitsupdates sofort. Jede Änderung läuft durch alle Tests, bevor ein Image entsteht.
- **Prüfungen** (Workflow „WehrPortal CI“): auf Pushes und Pull Requests nach `main` laufen Tests (PostgreSQL 18), `composer audit`, `npm audit`, Larastan (Level 5), Gitleaks sowie eigene Dateien-Prüfschritte. Diese Läufe veröffentlichen keine Images; dafür ist der Release-Workflow zuständig. Kein Workflow löst ein Deployment aus.
- **Wöchentlich** (Workflow „WehrPortal Security“): Composer- und npm-Abhängigkeitsaudits sowie ein Scan des veröffentlichten `ghcr.io/timux/wehrportal:latest` auf kritische und hohe Schwachstellen. Vor dem ersten Image kann dieser Scan noch nicht erfolgreich sein. Funde stehen im Security-Tab des Repositorys.
- **Releases:** Versions-Tags und veröffentlichte GitHub Releases durchlaufen dieselben CI-Prüfungen. Erst danach wird das Image gebaut, mit Trivy auf kritische Schwachstellen mit verfügbarer Korrektur geprüft und nach GHCR übertragen. Es gibt keinen produktiven Deployment-Webhook. Wöchentliche Dependency-/Image-Scans entdecken später bekannt gewordene Lücken; Screenshots bleiben manuell ausführbar.
- **Dependabot:** Wöchentliche, gruppierte Updates für Composer, npm (App und Playwright unter `tests/e2e`), Docker (Produktion und Devcontainer) und GitHub Actions mit begrenzter Zahl offener PRs. Größere Stack-Upgrades bei Composer/npm der App werden bewusst geplant. Im Repository `TimUx/WehrPortal` sind Sicherheitsmeldungen und automatische Sicherheitsupdate-PRs zusätzlich aktiviert. Bei einer eigenen Repository-Kopie separat in GitHub aktivieren; die Konfigurationsdatei allein schaltet diesen Dienst nicht ein.
- **In der App:** Security-Header auf jeder Antwort (`X-Content-Type-Options`, `X-Frame-Options` – außer dem öffentlichen Kalender zum Einbetten –, `Referrer-Policy`, `Permissions-Policy`, HSTS unter HTTPS und eine Content-Security-Policy, vorerst im Beobachtungsmodus „Report-Only“). Hochgeladene Dateien werden mit festem Dateityp ausgeliefert, nur PDFs und Fotos öffnen im Browser, alles andere wird heruntergeladen. CSV-Exporte entschärfen Zellen, die in Excel als Formel laufen würden. Externe Kalender lassen sich nur von öffentlichen Adressen abrufen. Links in Mails nehmen ihre Adresse immer aus `APP_URL`. Session-Cookies sind unter HTTPS automatisch `Secure`.
- **Einladungslinks:** Den Login-Link als QR-Code oder zum Kopieren zeigt die Personalverwaltung nur für Kameraden, die sich noch nie angemeldet haben (Admins für jeden); alle anderen bekommen die Einladung per Mail an ihre eigene Adresse. Mails mit Login- oder Aktionslinks werden im Mail-Protokoll ohne Inhalt gespeichert.

### Lizenz

WehrPortal ist freie Software unter der [GNU Affero General Public License v3.0](../LICENSE) (AGPL-3.0-only). Jede Wehr darf WehrPortal kostenlos nutzen, anpassen und weitergeben. Wer eine veränderte Version betreibt und anderen über das Netz zur Verfügung stellt, muss deren Quelltext ebenfalls unter der AGPL-3.0 offenlegen. Die ursprüngliche Anwendung [openwehr](https://github.com/roberteinsle/openwehr) von Robert Einsle und weiteren Beitragenden sowie die Erweiterungen aus `TimUx/openwehr-extended` bleiben als Herkunft anerkannt. Frühere Copyright- und Urheberhinweise und die Git-Historie bleiben gültig; die Umbenennung beansprucht keine alleinige Urheberschaft.
