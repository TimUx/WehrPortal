# Rollen, Berechtigungen und Daten-Scopes

## Ist-/Soll-Abgleich

Das Sollmodell dieser Tabelle ist die Vorgabe des Audits, nicht eine Behauptung über existierende Rollen. Im Ist gibt es Modulzugang/-rollen und Standortmitgliedschaft, aber kein nachgewiesenes zentrales Rollenmodell mit den Ebenen Kommune, Standort, Modul, Verein und Datenklasse.

| Rolle/Zugriff | Implementierter Mechanismus | Soll und Lücke |
|---|---|---|
| Globaler Administrator | `users.is_admin`; LocationScope lässt ihn ungefiltert passieren (`LocationScope.php:27-30`). | Globale Administration darf Schutzregeln für sensible Daten nicht pauschal umgehen; sensible Sonderzugriffe müssen einzeln begründet und protokolliert werden. |
| Standort-Administrator | `x_location_user.role = admin`; `LocationAccess::canManage()` begrenzt Standortverwaltung (`LocationAccess.php:40-58`). | Der Standortadmin ist nicht automatisch Modulmanager oder Standortführung für alle Module. Diese Trennung muss in UI und Berechtigungsübersicht sichtbar sein. |
| Modulrolle | `module_user` mit Rolle auf Nutzer/Modul (`2026_10_07_100001_create_module_user_table.php:11-17`); Abfragen über `hasModuleRole()` (`User.php:299-305`). | Standortbezug, Aktion und Datenklasse fehlen im Zuweisungsschema; siehe TEN-02. |
| Kommune / Bürgermeister / Brandschutzamt / GBI/SPI | Keine allgemeine, über Module hinweg zentrale Profil-/Capability-Zuweisung nachgewiesen. `uebergreifend` ist eine Fachsonderrolle, keine allgemeine Ebene. | Nur freigegebene Module, Standorte und Aggregationsstufen; kein pauschaler Zugriff auf Personal-, Gesundheits- oder Finanzdetails. |
| Vereinsvorstand | Vereinsspezifische Zuordnung über `x_vv_association_user` (`AssociationAccess.php:16-20,25-49`). | Soll: ausschließlich der zugeordnete Verein. Rollen-/Standortwechsel und Exporte zusätzlich testen. |
| Mitglied | Eigenes Konto plus zugewiesene/automatisch verfügbare Module; Standortfilter nur an ausgewählten Modellen. | Eigene Daten und ausdrücklich freigegebene Aktionen; kein Zugriff allein über versteckte UI-Elemente beschränken. |
| Eltern / Token / öffentliche Zugriffe | Einzelne signierte, begrenzte oder öffentliche Flows; vollständige Route-/Ablaufinventur nicht möglich. | Token muss Zweck, Objekt, Aktion, Gültigkeit und Standort binden; Widerruf und Rate Limits testen. |
| Hintergrundjob / Webhook | Kein angemeldeter Nutzer; LocationScope greift dann nicht (`LocationScope.php:23-25`). | Expliziter, zur Ausführungszeit erneut geprüfter Empfänger- und Standortkontext. |

## Datenklassifikation und Zielmodus

Die folgenden Modi sind Empfehlungen gemäß Vorgabe. „Teilweise belegt“ bedeutet nur, dass ein Hauptmodell oder ein expliziter Sonderpfad vorhanden ist; es beweist keine vollständige Klassifikation aller Kindtabellen.

| Datenbereich | Beobachteter Standortbezug | Empfohlener Modus / Schutz |
|---|---|---|
| Personal / Personalverwaltung | `users` in zentraler Scope-Liste; Kinddaten und Akten nicht automatisch enthalten (`locations.php:9-21`). | Standortgebunden; Zugriff auf eigene/zugewiesene Personalaktionen beschränken. |
| Einsatzberichte | Report-Modell gelistet; Child-/Besatzungs-/Medienpfade müssen separat geprüft werden. | Standortgebunden; Zusammenarbeit explizit statt implizit teilen. |
| Verbandbuch, Expositionen, Atemschutz, Führerscheine | Keine vollständige zentrale Scope-Klassifikation belegt. Enthalten besonders schützenswerte Daten. | Standortgebunden; Personenbezug und Details standardmäßig nicht an kommunale Profile freigeben. |
| Finanzen | Kein Modell aus diesem Modul in `locations.php:9-21` gelistet. | Standortgebunden; kommunale Sicht allenfalls freigegebene Aggregate. |
| Fahrzeuge / Ausrüstung / Kleiderkammer | Fahrzeug-, Ausrüstungs-, Kategorie- und Kleiderartikel-Hauptmodelle gelistet. | Hybrid nur bei explizitem Zentralbestand; Ausgaben, Verantwortung und personenbezogene Historie lokal binden. |
| Wartung / Mängel | Mängel-Hauptmodell gelistet; Wartungsprotokolle, Ereignisse und Anhänge nicht automatisch eingeschlossen. | Hybrid/standortgebunden je Objekt; Parent-Standort vererben. |
| Lehrgänge / Unterweisungen | Kein vollständiger Scope für Anmeldungen, Teilnahmen und Nachweise nachgewiesen. | Kurskatalog ggf. übergreifend; Teilnahme, Signatur und personenbezogene Nachweise standortgebunden. |
| Kalender / Schichtplan / Übungsdienste | `kal_events` ist gelistet; Empfänger, Antworten und Synchronisationsdaten separat. | Hybrid; gemeinsame Termine/Partnerstandorte explizit auswählen. |
| Mitteilungen | `announcements.ext_location_id` und explizite Empfängerlogik vorhanden (`AnnouncementService.php:100-123`). | Hybrid mit expliziter Freigabe und gleichen Regeln für Attachments, Push und Reads. |
| Vereinsverwaltung | Mitgliedsmodell und Verein besitzen Standort-/Vereinsbezug; sensible Felder verschlüsselt. | Standort-/Vereinsgebunden; keine automatische Verbindung zu kommunalen Personalakten. |
| Dateien / Offline | Eigenständige ACL; lokale Browsercaches bei Standort-/Logout-Wechsel gesondert prüfen. | Standort-/ACL-gebunden; Cacheinvalidierung und Freigabelinks explizit widerrufbar. |
| Technische Stammdaten | Modulaktivierung und nicht personenbezogene Konfiguration global. | Bewusst global nur nach dokumentierter Entscheidung; Zugriffsänderungen protokollieren. |

## Modul-/Extension-Matrix

„Zentrale Liste“ bezieht sich ausschließlich auf `extensions/Locations/config/locations.php:9-21`. „Nicht gelistet“ bedeutet **nicht**, dass sicher ein Datenleck existiert: eigene Policies/Scopes können bestehen, wurden aber nicht für alle Untermodelle, Querypfade und Exporte verifiziert. Die Zielmodi sind Empfehlungen und keine Ist-Implementierung.

| Modul / Extension | Zentral gelistetes Modell oder Sonderpfad | Empfohlener Scope / Prüfbedarf |
|---|---|---|
| Atemschutz | Kein Modell in zentraler Location-Liste. | Standortgebunden; Readiness, Teams und Einsatz-/Übungsdaten getrennt prüfen. |
| Ausrüstung | Equipment und Kategorien gelistet. | Hybrid; Ereignisse, Schäden, Inventur und Personenausgaben vom Parent/Standort ableiten. |
| Einsatzbericht | Report gelistet. | Standortgebunden; Besatzung, Fotos, Fahrzeuge und Zusatzdaten vom Report erben lassen. |
| Expositionen | Kein Modell gelistet. | Standortgebunden und besonders schützenswert; keine pauschale übergreifende Detailansicht. |
| Fahrtenbuch | Kein Modell gelistet; Verbindung zu Fahrzeugen. | Standortgebunden; Fahrten, Belege, Korrekturen und Kosten am Fahrzeug-/Standortscope prüfen. |
| Fahrzeugbuchung | Fahrzeug gelistet, Buchungen/Entscheidungen nicht in der Liste. | Standortgebunden; Buchung, Entscheidung und Kommentare am Fahrzeugstandort prüfen. |
| Fahrzeuge | Shared vehicle ist gelistet; abhängige Profile/Fristen folgen nicht automatisch. | Hybrid; Fahrzeuge/Fristen selektiv teilen, Dokumente und Akten gesondert schützen. |
| Finanzen | Kein Modell gelistet. | Standortgebunden; kommunale Sicht ggf. nur auf ausdrücklich freigegebene Aggregate. |
| Führerscheine | Kein Modell gelistet. | Standortgebunden; Fotos und Nachweise besonders restriktiv behandeln. |
| Gefahrenmatrix | Kein Modell gelistet. | Standortgebunden; zentrale Auswertung nur nach Aggregations-/Freigaberegel. |
| Gefahrstoffe | Kein Modell gelistet. | Hybrid; allgemeiner Katalog kann global sein, Bestand und Tätigkeiten standortgebunden. |
| Kalender | Event gelistet. | Hybrid; Antworten, Umfragen, Tokens und gemeinsame Termine separat binden. |
| Kleiderkammer | Artikelmodell gelistet. | Hybrid; Zentralbestand explizit konfigurieren, Ausgabe-/Personenhistorie separat begrenzen. |
| Lehrgänge | Kein Modell gelistet. | Hybrid; Kurskatalog ggf. gemeinsam, Anmeldung, Teilnahme und Nachweis standortgebunden. |
| Mängelmanagement | Defect gelistet. | Hybrid; Events, Fotos und Geräte-/Fahrzeugbeziehung vom Standort des Parentobjekts ableiten. |
| Mitteilungen | Eigener `announcements.ext_location_id`-Pfad und Empfängerfilter. | Hybrid; Anhänge, Lesestatus, Push und externe Ziele mit gleichem Scope testen. |
| Objektpläne | Kein Modell gelistet. | Hybrid; Versionen, Freigabe und Offlinecache vom Objekt-/Standortscope erben. |
| Personalverwaltung | `users` gelistet, nicht automatisch alle Personalakten-Kinddaten. | Standortgebunden; Führungs-/Kommunalzugriff auf freigegebene Aggregate begrenzen. |
| Schichtplan | Kein Modell gelistet. | Hybrid; Pläne, Schichten und Teilnahmen lokal, standortübergreifende Dienste explizit. |
| Schlüssel | Kein Modell gelistet. | Standortgebunden oder explizit hybrid; Übergaben und Personenbezug besonders prüfen. |
| Übungsdienste | Kein Modell gelistet; Kalenderintegration. | Hybrid; gemeinsame Übung und Synchronisationsdaten ausdrücklich zuordnen. |
| Unterweisungen | Kein Modell gelistet. | Hybrid; Themenkatalog ggf. gemeinsam, Termine/Signaturen/Nachweise standortgebunden. |
| Verbandbuch | Kein Modell gelistet. | Standortgebunden und besonders schützenswert; Zugriffe/Logs getrennt testen. |
| Wartung | Kein Modell gelistet; baut auf Ausrüstung auf. | Standortgebunden; Protokolle und Anhänge vom Equipment-/Standortscope erben. |
| Wasserkarte | Kein Modell gelistet. | Hybrid; Infrastruktur ggf. geteilt, Prüfungen, Zuständigkeiten und Änderungen protokollieren. |
| Anwesenheit (Extension) | Eigene Standortlogik laut Provider/Moduldokumentation; nicht Teil der zentralen Liste. | Standortgebunden; Teilnehmerlisten, Vorlagen und Dateien komplett prüfen. |
| Dateien (Extension) | Eigene ACL- und Standortlogik. | Hybrid; Share, Gruppe, Knoten, Version und Papierkorb müssen denselben Scope erben. |
| E2E (Extension) | Test-/Screenshotumgebung. | Bewusst test-only/global; niemals als produktiver Auth-/Datenpfad aktivieren. |
| EinsatzberichtPlus (Extension) | Ergänzt Einsatzberichte. | Standortgebunden; Zusatzpersonen und Zusatzdaten müssen den Report-Parent erben. |
| Hydrantenkontrolle (Extension) | Eigene Standort-/Stadtlogik und Fachrolle. | Hybrid; übergreifende Rechte nur explizit und mit klarer Stadt-/Standortgrenze. |
| Locations (Extension) | Standort- und Mitgliedschaftsinfrastruktur. | Bewusst globale Konfiguration; Zuordnungsänderungen nur global autorisiert, mit Audit/Dry-Run. |
| Mitteilungen-Kanäle (Extension) | Folgt den Mitteilungszielen; eigener Versandpfad. | Hybrid; Empfängerkreis, externe Ziele und Versandlogs standortbezogen prüfen. |
| Offline (Extension) | Browsercache/Service Worker. | Nutzer-/Standortkontext; Logout, Wechsel, Ablauf und Widerruf müssen Cache invalidieren. |
| OIDC (Extension) | Globale Authentisierung; Standort-Provisionierung nicht vollständig geprüft. | Globaler IdP, aber lokale Rollen-/Standortvergabe; Identität darf nicht automatisch Rechte erteilen. |
| Vereinsverwaltung (Extension) | Vereins-/Mitgliedsmodell mit eigener AssociationAccess. | Standort-/vereinsgebunden; Mitglieder- und Bankdaten nicht automatisch mit Personalakten teilen. |

## Verbindlicher Autorisierungsentscheid (Ziel)

Für jede Anfrage und jede ausgebbare Datenklasse soll serverseitig derselbe Entscheid gelten:

1. Modul ist aktiv und der Benutzerzugang besteht.
2. Objekt/Parent gehört zu einem freigegebenen Standort oder ist nachweislich bewusst geteilt.
3. Rolle/Capability erlaubt die konkrete Aktion (lesen, schreiben, freigeben, exportieren).
4. Datenklasse und Zweck erlauben genau diese Sicht, insbesondere bei sensiblen personenbezogenen Daten.
5. Für öffentliche/tokenbasierte oder asynchrone Pfade gelten dieselben Objekt-, Aktions- und Standortregeln.

Ein Rollen- oder Standortwechsel muss bei der nächsten Aktion greifen. Zusätzliche Lesezugriffe sollten Profil, Modul, Aktion, Datenklasse, Standort und Gültigkeitsende festhalten und protokollieren. Änderungen an Datenklassifikation oder Standortzuordnung benötigen Dry-Run, Konfliktvorschau, Audit-Eintrag und Regressionstests.

## Findings

- **TEN-01, TEN-02, TEN-03, TEN-04:** Details in [02 Sicherheitsprüfung](02-security-audit.md).
- Ein zentraler Rollenüberblick je Person (Standorte, Modulrollen, Vereinsrolle, Führungsprofil, Ablaufdatum) wurde nicht nachgewiesen; eine Inventarisierung aller verstreuten Rollenverwendungen war nicht vollständig.
- Die aktuelle Scope-Konfiguration deckt nicht automatisch Pivots, Child-Datensätze, Dateien, Exporte, Suchindizes, Statistiken oder Schedulerjobs ab.

## Sichere Umsetzungsreihenfolge

1. Schutzklassen und beabsichtigtes Teilen pro Tabelle beschließen; `NULL` nicht als Ersatz dafür verwenden.
2. Rollen- und Capabilitymatrix inklusive Standort und Aktion von der UI-Logik unabhängig definieren.
3. Parent-/Kind-, Datei- und Exportzugriffe zentral prüfen und für asynchrone Aufträge Scope explizit mitführen.
4. Altbestand, Schlüssel, Fremdschlüssel, Standortwechsel und bestehende Zuweisungen per Dry-Run migrieren.
5. Positive und negative Tests pro Rolle, Standort, Datenklasse und Zugriffskanal als Release-Gate verpflichtend machen.
