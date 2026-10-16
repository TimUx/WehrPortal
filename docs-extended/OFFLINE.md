# Offline und PWA

openwehr hat bereits ein PWA-Manifest, eine Offline-Fallback-Seite und Push-Benachrichtigungen. Der Root-Service-Worker wird über `/sw.js` mit `Service-Worker-Allowed: /` ausgeliefert. Er zeigt bei fehlender Verbindung die Fallback-Seite und cached nur die Offline-Seite sowie öffentliche, versionierte statische Assets. Er cached keine Navigationen, angemeldeten Seiten, Login-, Admin- oder Livewire-Anfragen. Objektpläne sind die bewusst getrennte Ausnahme: ihr eigener Service Worker speichert Inhalte im Bereich `/objektplaene`.

## Offline-Entwürfe

Die Erweiterung speichert nur ausdrücklich markierte Eingabefelder eines Livewire-Formulars lokal in IndexedDB. Der erste Pilot ist das Formular für Löschwasserquellen in der Wasserkarte. Auswahl, Zahlen, Lage und Koordinaten werden lokal gesichert; Notizen, Datei-Uploads und nicht markierte Felder bleiben außen vor. Ein vorhandener Entwurf kann wiederhergestellt oder verworfen werden.

Ein Entwurf wird **nicht** im Hintergrund gesendet. Livewire-Snapshots, CSRF-Token und Sitzungen dürfen nicht blind erneut abgespielt werden. Nach der Wiederherstellung muss die Person das Formular prüfen und normal absenden; damit bleiben Authentifizierung, Validierung und alle Geschäftsregeln auf dem Server unverändert. Es gibt derzeit keine geeigneten klassischen POST-Formulare, deren Offline-Übermittlung gefahrlos in eine Warteschlange gelegt werden könnte.

Entwürfe sind nach Benutzer und Formularseite getrennt, laufen nach 30 Tagen ab und werden beim Abmelden aus dem lokalen IndexedDB-Speicher gelöscht. Sie können auf gemeinsam genutzten Geräten bis dahin lokale Daten enthalten. Der Statusbereich zeigt den Verbindungszustand und die Anzahl gespeicherter Entwürfe. „Jetzt synchronisieren“ aktualisiert den Service Worker und den Entwurfsstatus; es sendet keine Formulardaten.

Die Funktion lässt sich mit `OFFLINE_ENABLED=false` abschalten. Das schaltet Offline-UI und Entwürfe ab; Manifest, Fallback-Seite und Service Worker bleiben als bestehende PWA-Grundlage aktiv. Die vorhandene Offline-Bibliothek der Objektpläne bleibt in ihrem eigenen Service-Worker-Bereich `/objektplaene`.
