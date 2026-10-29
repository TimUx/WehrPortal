# WehrPortal

[![Lizenz: AGPL-3.0](https://img.shields.io/badge/Lizenz-AGPL--3.0-3da639)](LICENSE)
[![PHP-Anforderung: ^8.2](https://img.shields.io/badge/PHP-%5E8.2-777bb4)](composer.json)
[![Blade: Laravel](https://img.shields.io/badge/Blade-Laravel-f05340)](composer.json)
[![JavaScript](https://img.shields.io/badge/JavaScript-ESM-f7df1e)](package.json)
[![Laravel: 12](https://img.shields.io/badge/Laravel-12-f05340)](composer.json)
[![Livewire: 4](https://img.shields.io/badge/Livewire-4-fb70a9)](composer.json)
[![Filament: 5](https://img.shields.io/badge/Filament-5-fbbf24)](composer.json)
[![Tailwind CSS: 4](https://img.shields.io/badge/Tailwind_CSS-4-06b6d4)](package.json)
[![PostgreSQL: CI 18](https://img.shields.io/badge/PostgreSQL-CI%2018-4169e1)](.github/workflows/deploy.yml)
[![Docker](https://img.shields.io/badge/Docker-Image-2496ed)](Dockerfile)

<img src="public/images/wehrportal-logo.svg" alt="WehrPortal: rotes Portal mit Flamme" width="96" height="96">

**WehrPortal** ist eine Web-App für Freiwillige Feuerwehren: ein gemeinsamer Kern (Anmeldung, Kameraden, Startseite, Benachrichtigungen, Admin-Bereich) und Module, die jede Wehr einzeln einschaltet, etwa Kalender, Fahrzeugbuchung, Einsatzbericht, Atemschutz, Personalverwaltung oder Kleiderkammer. Die Bedienung ist für das Handy gemacht, die Anmeldung läuft ohne Passwort über einen Link per E-Mail. Oberfläche und E-Mails sind deutsch.

Du brauchst WehrPortal, wenn deine Wehr Termine, Fahrzeuge, Material, Ausbildung und Einsatzdokumentation an einem Ort verwalten will, ohne mehrere Insellösungen zu pflegen. Selbst betreibbar, ohne Cloud-Zwang.

## Screenshots

Beispieldaten einer Testinstallation, weitere Bilder in der [Galerie](docs/SCREENSHOTS.md#galerie).

| Startseite | Personalakte | Bestand |
|---|---|---|
| <img src="docs/screenshots/core/startseite.png" alt="Startseite"> | <img src="docs/screenshots/core/personalakte.png" alt="Personalakte"> | <img src="docs/screenshots/core/bestand.png" alt="Bestand"> |

## Dokumentation

| Für wen | Dokument |
|---|---|
| Kameraden | [User-Guide](handbuch/USER.md): Anmeldung, Navigation, Alltag |
| Admins | [Admin-Guide](handbuch/ADMIN.md): Ersteinrichtung, Module, Rollen |
| Betreiber | [Installationsguide](handbuch/INSTALLATION.md) und [Serverbetrieb mit Docker Compose](docs/BETRIEB.md): Installation, Konfiguration, Sicherheit, Updates, Releases |
| Alle | [Kernfunktionen](docs/KERNFUNKTIONEN.md): Startseite, Fahrzeugbuchung, Rollen, Admin-Bereich |
| Alle | [Module im Überblick und im Detail](docs/MODULE.md) |
| Alle | [Modulhandbücher](docs/README.md): Anwesenheit, Dateien, Locations, Offline/PWA, OIDC, Mitteilungen, Einsatzbericht |
| Entwickler | [Screenshots und E2E-Tests](docs/SCREENSHOTS.md), [Tech-Stack](docs/BETRIEB.md#tech-stack) |

## Schnellstart

```bash
composer setup && composer dev
```

Voraussetzung ist PostgreSQL; Details im [Installationsguide](handbuch/INSTALLATION.md).

## Herkunft und Lizenz

**[TimUx/WehrPortal](https://github.com/TimUx/WehrPortal)** ist auf der Idee und Codebasis von **[roberteinsle/openwehr](https://github.com/roberteinsle/openwehr)** von Robert Einsle und weiteren Beitragenden sowie den Erweiterungen aus `TimUx/openwehr-extended` entstanden und wird eigenständig weiterentwickelt. WehrPortal benötigt weder deren Repositories noch Images oder Updates; es startet als Neuinstallation ohne übernommene Bestandsdaten. Git-Historie und ursprüngliche Urheberhinweise bleiben erhalten.

Lizenz: [AGPL-3.0-only](LICENSE), Details in [Betrieb und Entwicklung](docs/BETRIEB.md#lizenz). Die App-Marke ([`public/images/wehrportal-logo.svg`](public/images/wehrportal-logo.svg)) ist eigenständig entworfen und verwendet keine fremden Wappen.
