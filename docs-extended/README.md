# Erweiterungen von openwehr-extended

[Zur Hauptdokumentation](../README.md) · [Fork-Leitfaden](../FORK.md)

Die Erweiterungen ergänzen die 25 Module des Upstreams. Sie werden über den Erweiterungs-Provider geladen; ob ein Modul für Kameraden verfügbar ist, wird davon getrennt unter **Admin → Module** gesteuert. Die Umgebungsvariablen in der Tabelle schalten die jeweilige Erweiterung selbst ab, sofern nicht anders angegeben.

## Inhalt

- [Übersicht](#übersicht)
- [Betrieb und Planung](#betrieb-und-planung)

## Übersicht

| Erweiterung | Zweck | Zugang, Aktivierung und wichtige Grenzen |
|---|---|---|
| [Hydrantenkontrolle](../README.md#hydrantenkontrolle) | Stammdaten, fortsetzbare Jahreskontrollen, Mängelcheckliste und echte PDF-Berichte. | Unter Admin → Module aktivieren und Rolle `hydrantenwart` vergeben (Admins ebenfalls berechtigt). `HYDRANTENKONTROLLE_ENABLED=false` lädt die Erweiterung nicht. Jahresbezogene Stammdatenkopien, Konfliktschutz beim Speichern, optionale Standortbegrenzung; keine automatische Wasserkarte-/Excel-Synchronisierung. |
| [Locations](LOCATIONS.md) | Mitglieder und ausgewählte Datensätze Standorten zuordnen. | `LOCATIONS_ENABLED=false` deaktiviert Scope und Standortverwaltung. Nur konfigurierte Modelle werden begrenzt; Standortloses (`NULL`) bleibt gemeinsam sichtbar. Keine vollständige Mandantentrennung. |
| [Offline/PWA](OFFLINE.md) | Offline-Fallback sowie lokale Entwürfe ausdrücklich markierter Formulare. | `OFFLINE_ENABLED=false` schaltet Statusanzeige und Entwürfe ab, aber nicht Manifest, Service Worker und Fallback-Seite. Keine automatische Übermittlung oder Synchronisierung von Formularen; Objektpläne nutzen einen separaten Worker. |
| [OIDC](OIDC.md) | Anmeldung über einen externen OpenID-Connect-Anbieter. | `OIDC_ENABLED=false` lädt OIDC nicht. Nur aktive Konten mit bestätigter E-Mail werden verknüpft; JIT-Anlage ist optional, IdP-Rollen werden nicht übernommen. Bei „Nur Single Sign-On“ lokale System-Admins und Notfallzugang erhalten. |
| [Anwesenheit](ANWESENHEIT.md) | Eigenständige Anwesenheitslisten, Vorlagen, PDF und E-Mail. | Modul nach Migration zunächst inaktiv; `ANWESENHEIT_ENABLED=false` schaltet die Erweiterung ab. Optional: Kalender zur Vorbelegung, Locations zur Standortbegrenzung und Offline für lokale Formularentwürfe. |
| [Dateien](DATEIEN.md) | Private Dateiablagen mit ACL, Versionen, Suche und optionaler Office-Bearbeitung bzw. öffentlicher Lesefreigabe. | Modul nach Migration zunächst inaktiv. Es gibt keinen `DATEIEN_ENABLED`-Schalter. Zugriff wird zusätzlich zu Modulzugang pro Ablage/Eintrag vergeben; öffentliche Links sind standardmäßig aus. Online-Office benötigt einen separat betriebenen Server. |
| [EinsatzberichtPlus](EINSATZBERICHT.md) | Zusätzliche Einsatzangaben und ein nicht blockierender Sollbesatzungshinweis. | `EINSATZBERICHT_PLUS_ENABLED=false` schaltet die Erweiterung ab. Nutzt die Rollen und Standortgrenzen des Einsatzberichts; Zusatzangaben sind derzeit nicht Teil von Druck oder Statistik. |

## Betrieb und Planung

- Erweiterungen und Module haben getrennte Aktivierung: ein aktiver Provider bedeutet nicht, dass ein Kamerad automatisch Zugriff auf ein Modul oder eine Dateiablage hat.
- Die Standortbegrenzung umfasst nur die konfigurierten Modelle und schützt nicht automatisch jede SQL-Abfrage. Zu den Grenzen siehe [Locations](LOCATIONS.md).
- Offline-Entwürfe bleiben lokal im Browser, laufen ab und müssen von der Person geprüft und regulär abgesendet werden. Es gibt keine automatische Formularsynchronisierung; siehe [Offline/PWA](OFFLINE.md).
- Die Dateien-Roadmap trennt umgesetzte Meilensteine von offenem Ausbau; geplante Punkte sind keine verfügbaren Funktionen: [Dateien-Roadmap](DATEIEN-ROADMAP.md).
- Für Fork-Updates, Image-Veröffentlichungen und die dokumentierten Eingriffe in Upstream-Dateien siehe den [Fork-Leitfaden](../FORK.md).
