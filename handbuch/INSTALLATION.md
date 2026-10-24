# Installationsguide

[README](../README.md) · [Admin-Guide](ADMIN.md) · [User-Guide](USER.md)

Dieser Guide richtet sich an Betreiber einer **neuen WehrPortal-Instanz**. Es gibt keine Übernahme von Installationen oder Daten aus Vorgängerprojekten. Alle Beispiele verwenden erfundene Angaben der FF Musterstadt.

## Inhaltsverzeichnis

- [Voraussetzungen](#voraussetzungen)
- [Produktiv installieren](#produktiv-installieren)
- [Mit Docker Compose starten](#mit-docker-compose-starten)
- [Vor der Freigabe prüfen](#vor-der-freigabe-prüfen)
- [Lokal entwickeln](#lokal-entwickeln)
- [Sichern und wiederherstellen](#sichern-und-wiederherstellen)
- [Updates einspielen](#updates-einspielen)
- [Fehler eingrenzen](#fehler-eingrenzen)

## Voraussetzungen

- **Server und HTTPS:** Für den Betrieb mit Docker Compose einen Linux-VPS bei einem Anbieter eigener Wahl mit mindestens 2 CPU-Kernen, 4 GB RAM und 50 GB Speicher vorsehen. Benötigt werden SSH-Zugang mit Administratorrechten, Docker Engine mit Compose-Plugin, eine eigene Domain mit Zugriff auf deren DNS-Einträge und ein HTTPS-Reverse-Proxy.
- **PostgreSQL:** Auch für Entwicklung und Tests verpflichtend; SQLite und MySQL werden nicht unterstützt. CI verwendet PostgreSQL 18, die mitgelieferte lokale Compose-Datei PostgreSQL 16. Die Datenbank muss das Anlegen von `btree_gist` erlauben; die Migrationen benötigen diese Erweiterung zum Schutz vor Doppelbuchungen.
- **Mailversand:** Ein erreichbarer SMTP-Server mit Zugangsdaten und passender Absenderadresse. Ohne Mailversand funktioniert die reguläre Anmeldung per E-Mail-Link nicht.
- **Geheimnisse:** Ein dauerhafter `APP_KEY`, Datenbank- und SMTP-Zugangsdaten sowie mindestens eine eigene Admin-Adresse. Diese Angaben nicht in Git speichern.
- **Persistenter Speicher:** Datenbank und App-Dateispeicher müssen Containerwechsel überstehen. Insbesondere das Modul Dateien speichert Inhalte außerhalb der Datenbank.

Für eine Installation ohne fertiges Image oder für Entwicklung werden zusätzlich PHP 8.4 mit den benötigten Erweiterungen (unter anderem `pdo_pgsql`, `intl`, `gd`, `exif`), Composer und Node.js/npm gebraucht. Das Produktionsimage und der Devcontainer bringen ihre Laufzeitumgebung mit.

## Produktiv installieren

Die vollständige Schritt-für-Schritt-Anleitung für **VPS → Docker Compose → HTTPS → WehrPortal** steht unter [Installation in der README](../README.md#installation). Sie ist unabhängig vom Hosting-Anbieter und benötigt keine Verwaltungsplattform.

1. **Server vorbereiten:** Docker Engine und Compose-Plugin installieren, Domain auf den Server zeigen lassen, HTTPS-Reverse-Proxy einrichten und nur notwendige Ports öffnen. PostgreSQL und Redis nicht öffentlich freigeben.
2. **Datenbank vorbereiten:** Compose stellt PostgreSQL intern bereit und initialisiert die Datenbank beim ersten Start. Ein starkes `DB_PASSWORD` setzen und regelmäßige Sicherungen einrichten. Bei einer bewusst extern betriebenen Datenbank die Verbindungswerte in der Compose-Datei für App und Worker entsprechend anpassen.
3. **Version wählen:** Nur tatsächlich veröffentlichte Tags aus den [WehrPortal-Releases](https://github.com/TimUx/WehrPortal/releases) verwenden. Der Release-Workflow veröffentlicht nach erfolgreichen Tests und Image-Scan `ghcr.io/timux/wehrportal:<Release-Tag>`, zum Beispiel `v0.9.5.5`. `latest` folgt stabilen Releases, nicht dem Entwicklungsstand auf `main`. Vor dem Deployment den erfolgreichen Workflow und das vorhandene Package prüfen. Keine Images anderer Projekte als Ersatz verwenden.
4. **App konfigurieren:** Die [Umgebungsvariablen der Serveranleitung](../README.md#4-wehrportal-konfigurieren) übernehmen und alle Platzhalter ersetzen. Besonders wichtig sind die öffentliche HTTPS-Adresse als `APP_URL`, `APP_KEY`, `DB_PASSWORD`, `MAIL_*` und `ADMIN_EMAILS`. Compose setzt `APP_ENV=production`, `APP_DEBUG=false` und die interne Datenbankverbindung selbst. Für SMTP auf Port 465 gilt `MAIL_SCHEME=smtps`, auf Port 587 `MAIL_SCHEME=smtp`.
5. **Speicher einbinden:** Im Produktionscontainer `/var/www/html/storage/app` dauerhaft und für den App-Benutzer schreibbar einbinden. Dort liegt unter anderem `private/dateien`. Ein Container ohne persistentes Volume ist für diese Dateiablage ungeeignet.
6. **Starten:** Port `8080` an den Reverse-Proxy anbinden und `/up` als Health-Check verwenden. Mit `AUTORUN_ENABLED=true` laufen Migrationen beim Start. Der mitgelieferte Scheduler erledigt Erinnerungen und Aufräumarbeiten; für den synchronen Mailversand ist kein Queue-Worker erforderlich.
7. **Ersten Admin anmelden:** Die Adresse aus `ADMIN_EMAILS` auf `/anmelden` eingeben, die Mail öffnen und die Anmeldung bestätigen. Danach mit dem [Admin-Guide](ADMIN.md#ersteinrichtung-in-sinnvoller-reihenfolge) fortfahren.

**Anwendungsschlüssel einmalig erzeugen:**

```bash
echo "base64:$(openssl rand -base64 32)"
```

Den gesamten ausgegebenen Wert als `APP_KEY` speichern und gesichert aufbewahren. Nicht bei jedem Deployment neu erzeugen: Mit ihm werden unter anderem gespeicherte Zugangsdaten und geschützte Fachdaten verschlüsselt. Eine Datenbanksicherung ohne den zugehörigen Schlüssel reicht für eine Wiederherstellung nicht aus.

Die App speichert Zeitstempel in UTC und zeigt sie in `Europe/Berlin` an. Ein `APP_TIMEZONE`-Eintrag ändert diese feste interne Zeitzone nicht.

## Mit Docker Compose starten

Die mitgelieferte `docker-compose.yml` lädt das fertige Release-Image aus **GitHub Packages / GHCR**. Auf dem Zielsystem sind weder Composer noch Node.js oder ein lokaler Image-Build nötig. App und Worker verwenden denselben Versions-Tag; PostgreSQL und Redis bleiben separate Dienste.

1. `docker-compose.yml` und `.env.example` aus dem gewünschten Release in ein eigenes Betriebsverzeichnis übernehmen; `.env.example` dort als `.env` speichern.
2. In `.env` `WEHRPORTAL_VERSION` auf einen tatsächlich veröffentlichten Tag setzen. Einen einmaligen `APP_KEY` mit dem oben gezeigten Befehl sowie ein starkes `DB_PASSWORD` eintragen. Compose verweigert den Start ohne diese beiden Werte.
3. `ADMIN_EMAILS`, die öffentliche `APP_URL` und die `MAIL_*`-Werte für euren SMTP-Server eintragen. Compose liest diese Werte aus `.env` und übergibt sie den Containern. Der Mailer ist ohne Konfiguration `log`, es werden dann keine Mails versendet.
4. Bei einem Reverse-Proxy direkt auf dem VPS im Dienst `app` die Portfreigabe `"8080:8080"` durch `"127.0.0.1:8080:8080"` ersetzen. Andernfalls veröffentlicht Docker die App auf allen Netzwerkschnittstellen. Für automatischen Wiederanlauf bei allen vier Diensten jeweils `restart: unless-stopped` auf derselben Ebene wie `image:` ergänzen.
5. Images laden und die Dienste starten:

   ```bash
   docker compose pull
   docker compose up -d
   docker compose ps
   docker compose logs --tail=100 app
   ```

6. `http://127.0.0.1:8080/up` am Server prüfen, davor einen HTTPS-Reverse-Proxy für die öffentliche Adresse einrichten und die erste Anmeldung testen. Ein vollständiges Beispiel mit Caddy als Systemdienst steht unter [HTTPS-Reverse-Proxy einrichten](../README.md#6-https-reverse-proxy-einrichten).

Die benannten Volumes speichern Datenbank, Redis und App-Dateien dauerhaft. **Nicht mit `docker compose down -v` löschen.** Ein geändertes `DB_PASSWORD` ändert das Passwort einer bereits initialisierten PostgreSQL-Datenbank nicht automatisch; bestehende Zugangsdaten koordiniert in Datenbank und Konfiguration ändern.

**Registry-Zugriff:** Das Package muss nach der ersten Veröffentlichung in GitHub Packages einmal auf **Public** gestellt werden, damit normale Installationen es ohne Anmeldung laden können. Bei bewusst privaten Packages vor dem Pull `docker login ghcr.io` mit einem dafür berechtigten Konto und Token mit `read:packages` verwenden. Zugangsdaten nicht in die Compose-Datei schreiben.

Für ein Update zuerst konsistent sichern, dann `WEHRPORTAL_VERSION` in `.env` ändern und erneut `docker compose pull && docker compose up -d` ausführen. Das ist ein bewusster Versionswechsel, kein automatisches Deployment.

## Vor der Freigabe prüfen

- Die öffentliche Adresse lädt über HTTPS und `/up` antwortet erfolgreich.
- Der Admin kann einen echten Login-Link empfangen und sich damit anmelden. `MAIL_MAILER=log` ist nur zum Ausprobieren geeignet und verschickt keine E-Mails.
- Unter **Admin → Einstellungen** stimmen Wehrname, Kurzname, Logo und Mail-Signatur.
- Unter **Admin → Module** sind nur gewünschte Module aktiv; Zugänge und Fachrollen sind bewusst vergeben.
- Ein Testkonto ohne Adminrechte sieht nur seine freigegebenen Inhalte. Bei mehreren Standorten zusätzlich die Standortgrenzen prüfen.
- Die Dateiablage bleibt nach einem Redeploy erhalten, wenn das Modul Dateien verwendet wird.
- Datenbank, privater Dateispeicher und Schlüssel sind gesichert; eine Wiederherstellung in einer getrennten Instanz wurde erprobt.

Keine Demo- oder Screenshot-Seeder auf einer produktiven Datenbank ausführen.

## Lokal entwickeln

Der einfachste Entwicklungsweg ist der mitgelieferte **Devcontainer** beziehungsweise ein Codespace mit `.devcontainer/`. Er stellt PHP 8.4 und PostgreSQL 18 bereit. Das Post-Create-Skript richtet die Umgebung, Beispieldaten und die Testdatenbank ein.

Im vorbereiteten Devcontainer:

```bash
composer dev
```

Das Demo-Admin-Konto lautet `admin@example.com`; bei `MAIL_MAILER=log` steht der Login-Link in `storage/logs/laravel.log`. Der Link muss im Browser noch bestätigt werden.

**Ohne Devcontainer:** PostgreSQL und die PHP-/Node-Werkzeuge bereitstellen, `.env.example` nach `.env` kopieren und Datenbank sowie lokale App-Adresse eintragen. Dann für eine neue Entwicklungsinstallation:

```bash
composer setup
php artisan app:ensure-admins
composer dev
```

Vor `app:ensure-admins` in `.env` eine erfundene Testadresse als `ADMIN_EMAILS` setzen. `composer setup` erzeugt den Anwendungsschlüssel und ist deshalb **kein Update-Befehl** für vorhandene Instanzen.

Ein lokaler Quellcode-Build ist weiterhin ausdrücklich möglich. Aus dem vollständigen Checkout und mit den oben beschriebenen `.env`-Werten:

```bash
docker compose -f docker-compose.yml -f docker-compose.build.yml up --build -d
```

Nur diese zusätzliche Datei aktiviert `build:` und verwendet das lokale Image `wehrportal:local`. Die normale Compose-Datei baut nichts. Der Devcontainer behält seine eigene Entwicklungsumgebung mit PHP-Werkzeugen; er ist kein Produktions-Release-Image.

Die üblichen Prüfungen:

```bash
composer test
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=2G
npm run build
```

Tests benötigen die PostgreSQL-Datenbank `wehrportal_test` an `127.0.0.1:5432` mit Benutzer `wehrportal` und Passwort `secret`. Nur für diese lokale Testumgebung verwenden.

## Sichern und wiederherstellen

**Zusammen sichern:** PostgreSQL-Datenbank, den privaten App-Dateispeicher, `APP_KEY` und die Betriebs-/Umgebungskonfiguration. Die meisten Uploads liegen in der Datenbank; Inhalte des Moduls Dateien liegen unter `storage/app/private/dateien` und sind darin nicht enthalten.

Für einen konsistenten Sicherungspunkt Schreibzugriffe während der gemeinsamen Datenbank- und Dateisicherung unterbinden. Auch automatisierte Änderungen und gegebenenfalls Office-Bearbeitung berücksichtigen. Sicherungen verschlüsselt, zugriffsgeschützt und zusätzlich außerhalb des App-Servers ablegen. Tägliche Sicherungen und eine zusätzliche Sicherung vor jedem Update sind ein sinnvoller Mindestablauf.

**Wiederherstellung:** In einer getrennten Umgebung denselben Anwendungsstand bereitstellen, den passenden Datenbank- und Dateistand zurückspielen, den ursprünglichen `APP_KEY` und die Konfiguration setzen und Dateirechte prüfen. Testmails und externe Integrationen dort zunächst abschalten oder auf Testziele umstellen. Erst danach Anmeldung, Dateidownloads und wichtige Modulabläufe prüfen. Mindestens vierteljährlich erproben, bevor ein echter Ausfall eintritt.

PDF-Exporte sind nützlich, ersetzen aber kein vollständiges Backup.

## Updates einspielen

1. Release-Hinweise und mögliche Betriebsänderungen lesen; Zielversion und vorhandene Wiederherstellungsmöglichkeit prüfen.
2. Zusammengehörige Datenbank- und Dateisicherung erstellen und den bisherigen Image-Tag notieren.
3. `WEHRPORTAL_VERSION` in `.env` anpassen und `docker compose pull && docker compose up -d` ausführen. Änderungen an der Compose-Datei des Releases mit der eigenen Konfiguration abgleichen; Loopback-Portbindung und Neustartregeln beibehalten. Bei ausdrücklich selbst gebautem Image den passenden Quellstand bauen. `APP_KEY` und persistente Volumes beibehalten.
4. Start- und Migrationslogs auf Fehler prüfen. Danach Health-Check, Anmeldung, Mailversand und die von euch verwendeten Module kontrollieren.

Ein Push nach `main` deployt keine produktive Instanz. Datenbankmigrationen laufen beim Containerstart; ein Wechsel zum alten Image macht diese Änderungen **nicht** rückgängig. Wenn ein Rollback die alte Datenstruktur benötigt, den dazu passenden Datenbank- und Dateistand wiederherstellen. Nicht blind nur den Image-Tag zurücksetzen.

## Fehler eingrenzen

**Keine Login-Mail:** Adresse und Aktivstatus prüfen, SMTP-Daten und Absender kontrollieren und im **Admin-Dashboard → Verbindungen** die Verbindung prüfen lassen. Bei `MAIL_MAILER=log` im App-Log nachsehen. Ein neuer Link hilft nicht bei einem falsch konfigurierten Mailserver.

**App startet nicht:** Container-Logs lesen, insbesondere Datenbankverbindung, Migrationen, `btree_gist`, gesetzten `APP_KEY` und Schreibrechte prüfen. Produktiv keinen Debugmodus zur Fehlersuche öffentlich einschalten.

**Dateien nach Deployment weg:** Volume-Zuordnung für `/var/www/html/storage/app` prüfen. Ein neues leeres Volume ersetzt nicht das bisherige Datenverzeichnis; den zusammengehörigen Sicherungsstand verwenden.

**Modul fehlt:** [Modulzugang im Admin-Guide prüfen](ADMIN.md#module-und-rechte-vergeben). Bei Modulen mit `*_ENABLED` muss zusätzlich deren Provider geladen sein. Nach Änderungen an Umgebungsvariablen die App mit aktualisierter Konfiguration neu starten.

**Single Sign-On ausgefallen:** Die dokumentierten [lokalen Notfallzugänge und Wiederherstellungsbefehle](../README.md#oidc--single-sign-on-im-detail) verwenden; nicht ohne getesteten lokalen Admin auf ausschließlich SSO umstellen.
