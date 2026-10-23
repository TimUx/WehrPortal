# Test- und Abnahmematrix

Alle Fälle sind mit erfundenen Testdaten und einer PostgreSQL-Testdatenbank auszuführen. Pro relevante Kombination gibt es mindestens einen **erlaubten** und einen **verbotenen** Test. Versteckte Navigation ist kein Ersatz für einen direkten serverseitigen Negativtest.

## Matrix: Rollen × Datenklasse

| Identität | Standortgebundene Daten | Hybride Daten | Übergreifende Daten | Bewusst globale Daten |
|---|---|---|---|---|
| Globaler Administrator | Zugriff auf alle Daten aller Standorte positiv testen, einschließlich sensibler Inhalte (Entscheidung vom 21.10.2026). | Darf standortübergreifend lesen und verwalten. | Darf global lesen/ändern. | Darf verwalten. |
| Kommunale Administration | Fremde Personal-/Gesundheits-/Finanzdetails standardmäßig verweigern. | Nur freigegebene Module und Datenfelder; aggregierte Ansicht positiv, Detailansicht negativ. | Positiver Zugriff nur bei expliziter Zuweisung. | Gemäß globaler Administration. |
| Bürgermeister / Brandschutzamt | Personenbezogene Detailansicht negativ, sofern nicht explizit freigegeben. | Freigegebene Übersichten/Fristen positiv; nicht freigegebene Details negativ. | Leserechte gemäß Capability positiv/negativ abdecken. | Nur falls fachlich freigegeben. |
| GBI/SPI / Führungsprofil | Kein pauschaler Bypass sensibler oder fremder Standortdaten. | Nur nach Profil × Modul × Aktion × Datenklasse. | Explizite Zuweisung testen. | Nur dokumentierte Rechte. |
| Standortadmin / Feuerwehrführung | Eigener zugewiesener Standort positiv; fremder Standort negativ. | Nur zugeordnete Bestands-/Verantwortungsdaten. | Nur konkrete Freigaben positiv. | Nur Verwaltung, falls explizit erteilt. |
| Modulrolle | Erlaubte Aktion am zugeordneten Standort positiv; gleiche Aktion am fremden Standort negativ. | Bestandssicht getrennt von personenbezogener Ausgabe testen. | Kein automatischer globaler Zugriff. | Nur bei eigener Capability. |
| Vereinsvorstand | Personalakte anderer Standorte negativ. | Vereinsdaten nur des zugeordneten Vereins positiv; anderer Verein negativ. | Kein automatischer kommunaler Zugriff. | Nur öffentliche/global konfigurierte Informationen. |
| Mitglied | Eigene und ausdrücklich freigegebene Daten positiv; fremde Person/IDs negativ. | Eigene Ausgabe bzw. zugewiesenes Objekt positiv; fremde Historie negativ. | Kein Zugriff ohne Freigabe. | Nur zugängliche allgemeine Funktionen. |
| Elternteil / Gast / Tokeninhaber | Keine Login- oder Standortumgehung; Token nur eng begrenzte Aktion positiv. | Nicht freigegebene Datei/Terminantwort negativ. | Kein globaler Zugriff. | Nur öffentliche Daten/Route. |
| Queue-/Scheduler-/Webhook-Kontext | Scope muss explizit übergeben und beim Lauf erneut geprüft werden. | Empfängerliste muss nur freigegebene Standorte umfassen. | Versand nur an explizit freigegebene Empfänger. | Nur dafür klassifizierte Daten. |

**Hinweis:** Nicht existierende Rollen dürfen nicht als bestanden gelten. Erst implementieren und dann mit positivem und negativem Fall nachweisen. Die gegenwärtigen Module-Rollen sind nicht generell standortbezogen (TEN-02).

## Zugriffskanäle und Prüfanforderungen

| Kanal | Positivfall | Negativfall / Regression |
|---|---|---|
| Browser/UI | Berechtigte Aktion und sichtbarer aktiver Standort. | UI-Link verbergen und zusätzlich direkte URL/POST mit fremder ID verweigern. |
| Livewire | Erlaubte Aktion mit gültigem Zustand/Record. | Manipulierte Record-ID, Hydration, veraltete Session nach Entzug verweigern. |
| Direkte Webroute / Webhook | Token-/Login-Aktion auf zugeordnetem Objekt. | Fremder Standort, falscher Token, deaktiviertes Modul, abgelaufene Berechtigung verweigern. |
| Suche / Listen / Dashboard / Statistik | Nur erlaubte Parent- und Child-Datensätze zählen. | Keine fremden Namen, Summen, Trefferzahlen oder Metadaten preisgeben. |
| Datei / Upload / Download / Share | Berechtigter Nutzer kann freigegebenen Teilbaum verwenden. | Fremder Standort, geänderter Parent, entzogene Gruppe und abgelaufener Share erhalten keine Bytes/Metadaten. |
| CSV / PDF / ICS | Erlaubte Datensätze werden vollständig und korrekt exportiert. | Fremde Zeilen, Anhänge, Empfänger oder aggregierte Rückschlüsse fehlen. |
| E-Mail / Datenbankbenachrichtigung / Push | Nur autorisierte Empfänger erhalten Inhalt und Ziel-URL. | Standort-/Rollenentzug zwischen Job-Dispatch und Ausführung wirkt; keine fremden Namen/Details. |
| Scheduler / Queue | Job hat expliziten Scope und korrekte Ergebnisse. | Job ohne Scope bricht sicher ab statt global alle Datensätze zu verwenden. |
| Scan / QR / Offlinecache | Berechtigtes Objekt wird in autorisiertem Kontext geöffnet. | QR ist keine Berechtigung; Logout, Standortwechsel und Rechteentzug invalidieren Cache/Ansicht. |

## Lebenszyklus- und Migrationstests

| Szenario | Erwartete Abnahme |
|---|---|
| Standortwechsel A → B | Nächste Aktion nutzt B; A-Daten werden nicht aus alter Seite/Livewire-Aktion weiter angezeigt. |
| Mitgliedschafts-/Rollenentzug | Nächste Anfrage verweigert; keine veraltete Berechtigung aus Service-/Sessioncache. |
| Modul deaktiviert | Route, Livewire-Aufruf, Hintergrundversand und Export nicht erreichbar; einheitlicher Fehlerzustand. |
| Freigabe läuft ab/wird widerrufen | Keine weitere Nutzung; vorher versandte Token/Links entsprechend widerrufen oder ablaufen. |
| Standort deaktiviert/gelöscht | FKs, Eltern-/Kinddaten, Dateien, Logs, Benachrichtigungen und Wiederherstellung nach dokumentierter Regel behandelt. |
| NULL-/Altbestand | Dry-Run klassifiziert alle betroffenen Datensätze; private Datensätze werden nicht stillschweigend global sichtbar. |
| Migration neu/upgrade | Frische und aktualisierte PostgreSQL-Datenbank liefern dasselbe Schema und dieselben Scope-Invarianten. |
| Upstream-Sync | Konflikt-/Migrationsprüfung; Scope-Guard und Sicherheitsregression bestehen vor Merge. |

## Sicherheitsregressionen

- SEC-01: im privaten Testfall nur die konkrete autorisierte Nutzer-/Standortkombination erhält Objektinhalt; negative Fälle liefern 404/403 und keinen Metadatenkanal.
- TEN-01: Standortloser privater Datensatz wird für anderen Standort nicht sichtbar; bewusst geteilte Datensätze erfordern explizite Klassifikation und Freigabe.
- TEN-02/TEN-03: Modulrolle und konfigurierte Hauptmodelle verleihen keine Rechte auf fremde Kind-/Pivot-/Datei-/Exportdaten.
- TEN-04: Job-/Webhook-/Push-/ICS-Pfade erhalten bei fehlendem oder entzogenem Scope keine globalen Empfänger.
- Jede bestätigte kritische Berechtigungslücke erhält einen automatisierten Regressionstest, der vor dem Fix fehlschlägt und nach dem Fix besteht. Der Test enthält ausschließlich erfundene Daten.

## Performance-, UX- und Release-Abnahme

- PERF-01 Query-Count und Query-Plan von Empfänger-, Suche- und Exportpfaden mit repräsentativer Datensatzgröße messen; keine ungemessene Aussage über Laufzeit.
- Accessibility-Kernpfade manuell per Tastatur/Screenreader und automatisiert für repräsentative Seiten prüfen.
- Regressionen auf Desktop und Mobilgerät für Standortstatus, leere/Fehler-/403/404-Zustände und Formulare dokumentieren.
- Vollständige CI mit PHP 8.4, PostgreSQL 18, Pest, PHPStan, Pint, Composer-/npm-Audit und Image-Scan erfolgreich.
- Vor Freigabe Snapshot/Mergebase, ausgeführte Testmatrix und bekannte Restabweichungen protokollieren.
