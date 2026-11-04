# Implementierungsplan: Atemschutzverwaltung

**Status:** Kernumfang und Qualifikationshistorie umgesetzt und gezielt validiert
**Stand der Analyse:** 03.11.2026
**Geltungsbereich:** Phasenweise Erweiterung von `modules/Atemschutz/` und den ausdrücklich betroffenen allgemeinen Atemschutz-Kategoriepfaden.

## Kurzfassung und Empfehlung

Die zentrale Geräteträgeridentität ist bereits vorhanden: `as_wearers` enthält genau einen Datensatz je `users.id`. Die Atemschutzüberwachung verknüpft Truppmitglieder direkt mit `users.id`; Bereitschaft wird zentral durch `ReadinessService` berechnet. Diese Struktur sollte erhalten und zur digitalen Geräteträgerakte erweitert werden, nicht durch eine zweite Personentabelle ersetzt werden.

Für Atemschutzausrüstung gibt es bereits allgemeine Bestands- und Wartungsfunktionen. Die fachliche Verwaltung soll jedoch **ausschließlich in einem eigenen Bereich des Atemschutzmoduls** mit einem **eigenständigen Atemschutz-Gerätebestand** stattfinden. Atemschutzgeräte werden nicht in der normalen Ausrüstungs-/Geräteverwaltung geführt; Wehrführung und allgemeine Gerätewarte verwalten sie dort nicht. Der Atemschutzgerätewart verwaltet Atemschutzgeräte standortübergreifend. Da es noch keine produktiven Installationen mit Atemschutzgeräten in der Geräteverwaltung gibt, ist keine Übernahme bestehender Atemschutzgeräte oder gerätebezogener Wartungsdaten geplant. Das allgemeine Modul `Wartung` bleibt für neue Atemschutzgeräte außen vor; Atemschutz erhält eigene Prüfungs- und Wartungsabläufe.

Die bestehende Überwachung, PDF-Protokolle, Einsatzbericht-Verknüpfungen und Bereitschaftsberechnung bleiben erhalten. Prüffristen werden nicht pauschal aus Gerätetyp oder Hersteller abgeleitet, sondern manuell je Gerät auf Basis geprüfter Hersteller-/Fachangaben festgelegt und dokumentiert.

## 1. Ist-Zustand

### 1.1 Geräteträger, Qualifikation und Bereitschaft

- `modules/Atemschutz/database/migrations/2026_11_03_000000_create_atemschutz_tables.php` legt `as_wearers` mit eindeutigem `user_id` an. Name, Abteilung, Dienstgrad und organisatorischer Standort liegen bereits am verknüpften `users`-Datensatz und werden nicht als zweite Stammdatenkopie geführt.
- `as_wearers` hält Poolstatus, Notiz und Erinnerungsstatus. AGT-/CSA-Qualifikationen liegen in einer eigenen Historie; G26.3-Untersuchungen werden separat und ausschließlich mit Untersuchungs- und Gültigkeitsdatum gespeichert. Kennzeichnungen liegen in ihrer eigenen Zuweisungshistorie.
- `as_trainings` speichert manuelle Atemschutz-Nachweise nach Person, Art und Datum, mit Notiz, erfassendem Benutzer und optionaler Tragezeit/CSA-Kennzeichnung. Die Relation-Manager-Oberfläche erlaubt derzeit das Löschen von Nachweisen; ein eigenständiges fachliches Änderungsprotokoll gibt es nicht.
- `ReadinessService` ist die maßgebliche Bereitschaftsberechnung. Sie kombiniert lokale `as_trainings` mit abgeschlossenen Atemschutz-Lehrgängen (`lq_records`), als Atemschutz-Unterweisung markierten Terminen (`uw_*`), begonnenen Atemschutz-Trupps (`as_team_members`/`as_teams`) und – wenn aktiv – Einsätzen aus `eb_report_crew`/`eb_reports`. G26.3-, 12-Monats- und 30-Tage-Regeln sind an einer Stelle implementiert.
- Die bestehende Datenhaltung sieht bei G26.3 ausdrücklich nur Daten, keine Befunde vor. Diese Grenze muss bestehen bleiben.

### 1.2 Atemschutzüberwachung und ihre Abhängigkeiten

Die bestehenden Tabellen und Beziehungen sind:

```text
users
  └── as_wearers (unique user_id; zentrale Pool-/Atemschutzakte)
  └── as_trainings (Nachweise, direkt über user_id)

as_operations (Einsatz/Übung, optional eb_reports.report_id)
  ├── as_teams
  │     ├── as_team_members (user_id nullable, Name als Snapshot)
  │     │     └── as_readings (Druckverlauf je Teammitglied)
  │     └── as_warnings (Warnungen und Quittierung)
  ├── as_operation_locations → x_locations
  └── as_log (zeitgestempeltes Protokoll)
```

- `MonitorService` führt alle wesentlichen Zustandsübergänge der Überwachung durch; `Board` nimmt Geräteträger per RFID oder Auswahl aus dem Pool auf. Nicht einsatzbereite oder externe Truppmitglieder bleiben mit damaligem Namen und Begründung im Protokoll.
- `AtemschutzPdf` erstellt das bestehende PDF-Protokoll. `MonitorService::startTeam()` verknüpft gestartete Trupps mit der Besatzung eines Einsatzberichts; Berichts-, Personalakten-, Ausweis-, Badge-, Startseiten- und Jahresbericht-Integrationen lesen dieselbe Bereitschaftslogik.
- `AtemschutzServiceProvider` registriert Modulrollen, Gates, Filament-Ressourcen, Navigation, Livewire-Komponenten, Personalakte, Credentials, Badges, Dashboard, Jahresbericht und täglichen Fristenlauf.
- Die bestehenden Featuretests liegen in `tests/Feature/Atemschutz/AtemschutzTest.php`. Sie decken unter anderem Poolverwaltung, Bereitschaft, RFID, Standortgrenzen, Einsatzbericht-Verknüpfung, PDF und Erinnerungen ab.

### 1.3 Vorhandener Gerätebestand, Prüfungen und Kennzeichnung

- `modules/Ausruestung` verwendet `ar_equipment` als Geräte-Stammsatz. Vorhandene Felder sind u. a. `code` (generierte Inventarnummer), `manufacturer`, `model`, `serial_number`, `barcode`, Herstellungs-/Inbetriebnahme-/Kaufdatum, `next_inspection_at`, `status`, `location_id`, `compartment_id`, `ext_location_id` und ein RFID-Feld.
- `ar_inspections`, `ar_damages` und `ar_events` enthalten Prüfungen, Mängel und Verlauf. `EquipmentService` wickelt Prüfungen, Status, Schäden, Reparaturen, Umzüge und RFID-Zuordnung ab und schreibt Verlaufseinträge.
- `ar_categories` kennt bereits Atemschutzmasken, Pressluftatmer und Stahl-/Composite-Druckluftflaschen. Diese Bestandskategorien und deren vorhandene Startintervalle sind jedoch keine belastbare individuelle Fristfreigabe. Bei der Trennung müssen diese Kategorien und zugehörigen Datensätze aus den allgemeinen Verwaltungsoberflächen entfernt bzw. ausgefiltert werden, ohne sie ungeprüft zu löschen oder als allgemeingültige Hersteller- oder Prüfregel auszugeben.
- Die Standorterweiterung ergänzt `ar_equipment.ext_location_id`. `location_id`/`compartment_id` bezeichnen dagegen den physischen Lagerort bzw. Geräteraum. `Equipment` prüft bereits, dass Lagerort und organisatorischer Standort zusammenpassen.
- `modules/Wartung` ist derzeit auf `ar_equipment` festgelegt: Templates, Aufgaben und Protokolle referenzieren diese Geräte-IDs. `wm_protocols` speichert signierte, gehashte Prüfprotokolle mit Checkliste, Prüfer/Fachfirma und Anhängen; Datenbanktrigger schützen unterschriebene Protokolle und Anhänge vor Änderungen und Löschung. Der neue eigenständige Atemschutzbestand kann daran nicht direkt angeschlossen werden.
- RFID ist heute nicht einheitlich: `as_wearers.rfid_uid` und `ar_equipment.rfid` sind getrennte Einzelwerte. `Equipment::normalizeTag()` normalisiert Geräte-Tags. `ScanRegistry` und `/i/{code}` lösen bestehende Gerätecodes, Barcodes und Tags auf; die Zielseite setzt die Berechtigung durch. `App\Support\QrCode` rendert SVG, kein PNG.

## 2. Standort- und Berechtigungslogik

- Die organisatorische Zuordnung der Person kommt aus `users.ext_location_id` und den bestehenden Mitgliedschaften. Für Atemschutz wird kein paralleler Standortstamm eingeführt.
- `MonitorService::careLocationIds()`, `canCare()` und `canCareFor()` sowie die Abfrage- und Aktionsprüfungen in `WearerResource` bilden die bestehende Grenze: globale Atemschutzwarte/Admins können alle Geräteträger pflegen; die standortbezogene Wehrführung erhält nur den zugewiesenen Bereich. `tests/Feature/Atemschutz/AtemschutzTest.php` prüft diese Trennung.
- Die Überwachung verwendet bereits `LocationAccess`, aktive `x_locations`, `as_operations.ext_location_id` und `as_operation_locations`. Unzugeordnete historische Überwachungen bleiben globalen Atemschutzwarten und Admins vorbehalten.
- Der eigene Atemschutz-Gerätestamm führt eine organisatorische Standortzuordnung getrennt vom tatsächlichen Aufenthaltsort. Die Mehrstandortberechtigung des Atemschutzgerätewarts gilt unabhängig von diesen Geräteattributen; eine Werkstatt oder ein externer Dienstleister ist kein organisatorischer Standort.
- **Festgelegt:** Der Atemschutzgerätewart ist eine standortübergreifende Rolle für die Atemschutzausrüstungsverwaltung. Seine Geräteverwaltung wird nicht auf den aktiven Standort oder die Standortzugehörigkeit der Wehrführung begrenzt. Dies ist fachlich von den bestehenden standortbezogenen Rechten auf Geräteträgerakten und der allgemeinen Ausrüstungsverwaltung getrennt zu autorisieren.
- **Festgelegt:** Die Wehrführung ist jeweils genau einem Standort zugewiesen. Für die Verwaltung von Geräteträgerakten gilt daher das bestehende Verhalten über den Primärstandort `users.ext_location_id`; eine standortübergreifende Wehrführungsverwaltung ist nicht vorgesehen.
- Jeder neue Filament-/Livewire-Pfad muss serverseitig sowohl auf Ressourcen- als auch auf Objektebene prüfen. Menüausblendung, aktueller Standortkontext oder ein QR-Scan sind keine Berechtigung.

## 3. Zielnavigation und Zielarchitektur

Die sichtbaren Bezeichnungen werden wie vorgegeben verwendet:

```text
Atemschutz
├── Verwaltung
│   ├── Geräteträger
│   └── Atemschutzausrüstung
└── Operativ
    └── Atemschutzüberwachung
```

Wartung/Prüfungen und QR/RFID erhalten keine eigenen Navigationspunkte. Prüfungen, Dokumente, Kennzeichnungen, Werkstattübergabe und Historie sind Aktionen bzw. Relation Manager in der jeweiligen Geräteakte. Die bestehende Überwachung bleibt unter `/atemschutz` die operative Hauptseite. Das Filament-Plugin erhält eine Verwaltungsübersicht bzw. getrennte Ressourcen unter der Atemschutz-Navigationsgruppe; der Modulleisten-Einstieg führt weiterhin in die Verwaltung.

### 3.1 Single Source of Truth

1. **Person und Mitgliedschaft:** `users` bleibt Quelle für Identität, Namen, Abteilungen, Aktivstatus und Standort.
2. **Atemschutz-Pool:** der bestehende `as_wearers`-Datensatz bleibt die einzige Atemschutzakte pro Person (`user_id` unique). `is_active` beschreibt die Zugehörigkeit zum Atemschutzpool, nicht den Aktivstatus des WehrPortal-Kontos.
3. **Einsatz-/Übungshistorie:** `as_team_members.user_id` bleibt die Verbindung zur Person; der bestehende Name-Snapshot bleibt für historische Protokolle erhalten. `as_operations`, Teams, Messwerte, Warnungen und PDFs werden nicht umgeschrieben.
4. **Lehrgangs-/Unterweisungsdaten:** `Lehrgänge` und `Unterweisungen` bleiben Eigentümer ihrer dort erfassten Nachweise. Die Geräteträgerakte zeigt sie über dieselben Integrationen wie `ReadinessService` an, statt Kopien anzulegen.
5. **Atemschutzausrüstung:** ein eigenständiger `as_equipment`-Bestand wird ausschließlich im Atemschutzmodul verwaltet; allgemeine Ausrüstungs-/Geräteverwaltung führt keine Atemschutzgeräte.
6. **Prüfprotokolle:** neue Atemschutz-Prüfungen werden im Atemschutzmodul geführt. Bestehende, an `ar_equipment` gebundene `Wartung`-Protokolle bleiben unverändert und als historische Belege nachvollziehbar.

## 4. Geplante Datenbankänderungen

Die endgültigen Tabellennamen und Constraints werden bei Freigabe mit den vorhandenen Migrationen abgeglichen. Der folgende Zuschnitt ist die Empfehlung, kein bereits umgesetztes Schema.

### 4.1 Geräteträgerakte

- `as_wearers` erweitern, nicht durch eine neue Geräteträgertabelle ersetzen. Personenname, Standort, Eintritt und Austritt werden weiter aus `users` gelesen; Poolstatus und Atemschutznotiz bleiben moduleigene Daten.
- Neue Untersuchungshistorie (z. B. `as_wearer_examinations`) mit Geräteträgerbezug, Untersuchungstag, ausdrücklich gepflegtem Gültig-bis-Datum und Erfassendem. Es werden ausschließlich diese Datumsangaben gespeichert: keine Befunde, Diagnosen oder ärztlichen Nachweisdateien. Die vorhandenen Einzelwerte werden bei einer Migration einmalig als ältester bekannter Verlaufseintrag übernommen; danach wird der aktuelle Status aus dem neuesten gültigen Eintrag abgeleitet.
- AGT-/CSA-Qualifikationen und zusätzliche Atemschutzqualifikationen werden als datierte Nachweise mit Art, Datum, optionalem Ablauf und Beleg dargestellt. Lehrgangsdatensätze werden nicht kopiert. Für notwendige manuelle Angaben bleibt `as_trainings` die Atemschutzquelle und wird nur um fachlich benötigte Ergebnis-/Belegbeziehungen ergänzt.
- Bestehende Bereitschaftskennzeichen werden in einer schrittweisen Migration auf die neuen Verlaufsdaten umgestellt; `ReadinessService` bleibt die einzige Berechnung und erhält Regressionstests für alte und neue Datenquellen.

### 4.2 Atemschutzausrüstung

- Der Atemschutzbereich erhält seine eigene Verwaltung mit Atemschutzgerätewart-Berechtigung, Geräteakten, Typfiltern und fachspezifischen Aktionen. Atemschutzgeräte werden nicht als `ar_equipment` geführt und erscheinen daher nicht in der normalen Ausrüstungs-/Geräteverwaltung.
- Ein eigener Atemschutz-Gerätestammsatz (z. B. `as_equipment`) verwaltet Atemschutz-Inventarnummer, separate Gerätenummer, Hersteller, Modell, Seriennummer, Herstellungs-/Kauf-/Inbetriebnahmedaten, Status, Prüfgrundlage, Frist und RFID. Die Struktur wird fachlich eigenständig validiert und nicht an die Kategorien oder Statusübergänge der allgemeinen Ausrüstung gekoppelt.
- Der neue Atemschutzbestand startet leer. Es gibt keinen Daten-Backfill, keine Zuordnung alter `ar_equipment`-IDs und keine Übernahme allgemeiner Prüfungen/Verläufe oder `wm_protocols`. Atemschutzkategorien werden aus der allgemeinen Standard-Kategorienauswahl entfernt, damit künftig keine Atemschutzgeräte versehentlich als normale Ausrüstung angelegt werden; bestehende allgemeine Ausrüstungsdaten und Wartungsprotokolle bleiben ansonsten unangetastet.
- Typdetails werden in typgebundenen Erweiterungstabellen oder einer gleichwertig normalisierten Struktur gespeichert:
  - **Maske:** Maskentyp, Größe, Sichtscheibe, Anschluss, Material.
  - **Pressluftatmer:** Bauart, Tragesystem, Druckminderer, Warnsignal/Restdruckwarneinrichtung.
  - **Atemluftflasche:** Material/Bauart, Volumen, Betriebs- und Prüfdruck, Ventiltyp, Füllzustand und bei Bedarf letztes Fülldatum.
- Der Atemschutzbestand definiert eigene fachlich passende Zustände und Übergänge (einsatzbereit, defekt, in Reparatur, ausgesondert/gesperrt). Der Flaschenfüllstand ist ein eigenes Merkmal; die Einsatzbereitschaft wird anhand eines zentral gepflegten Mindestfüllstands je Flaschentyp bestimmt. Der Atemschutzgerätewart pflegt diese fachlichen Grenzwerte. Am Mindestwert ist die Flasche füllstandsseitig einsatzfähig; bei Unterschreitung und bei leerer Flasche ist die Einsatzbereitschaft automatisch gesperrt. Ein ausreichender Füllstand ersetzt keine technische Prüfung.
- Optionale Lungenautomaten, Flaschen und weitere Komponenten werden als Beziehungen zu anderen `as_equipment`-Datensätzen geführt. Dadurch haben Komponenten ihre eigene Inventarnummer, Historie und Prüfung, statt als Textduplikat im Gerät zu erscheinen.
- `as_equipment.next_inspection_at` bleibt das für Atemschutzlisten und Wartungsplanung maßgebliche Fälligkeitsdatum. Die Frist wird manuell je Gerät auf Basis geprüfter Hersteller-/Fachangaben eingetragen und mit ihrer Grundlage nachvollziehbar dokumentiert. Es gibt keine pauschalen, automatisch abgeleiteten Intervalle nach Typ oder Hersteller.
- Gerätemanuale, Fotos und Prüfbelege werden privat, berechtigungsgeprüft und ohne öffentliche Upload-URL ausgeliefert. Neue Atemschutz-Prüfprotokolle und Anhänge gehören zum eigenständigen Atemschutzbestand; bestehende `wm_attachments` verbleiben bei ihren unveränderten `wm_protocols`.
- Keine neue organisatorische Standortverwaltung. Der Atemschutzbestand erhält eine organisatorische Standortzuordnung und einen davon unabhängigen physischen Lagerort. Der standortübergreifende Atemschutzgerätewart kann Geräte unabhängig vom aktiven Standort verwalten. Für Atemschutzwerkstatt und externe Prüfstelle wird ein geeigneter Gerätestandorttyp vorgesehen.

### 4.3 RFID, QR und Verlaufsdaten

- Eine zentrale Atemschutz-Kennzeichnungslogik verwaltet aktive und deaktivierte RFID-Zuweisungen für `as_wearers` und `as_equipment`. Eine Zuordnung enthält Kennung/Standard, Objekt, Status, Vergabe-/Deaktivierungsdatum und verantwortlichen Benutzer. PostgreSQL-Constraints verhindern doppelte aktive UIDs; Neuzuordnung und Historieneintrag erfolgen atomar.
- Vorhandene `as_wearers.rfid_uid`-Werte bleiben erhalten; neue Atemschutzgeräte-RFID-Zuweisungen werden im Atemschutzbestand geführt. Atemschutzüberwachung und Atemschutzverwaltung verwenden denselben Kennzeichnungsdienst. Allgemeine Ausrüstungssuchpfade verwalten keine Atemschutzgeräte.
- Atemschutz erhält eigene Prüfungs-, Mängel- und Ereignisdatensätze für `as_equipment`; Status, organisatorischer Standort und physischer Lagerort werden mit vorher/nachher-Werten und Akteur protokolliert. Allgemeine `ar_events`, `ar_inspections` und `wm_protocols` verbleiben bei der allgemeinen Ausrüstung; es gibt keine Übernahme oder Umschreibung.
- Für den Atemschutzpass bleibt die bestehende befristete Credential-Prüfung getrennt von einem statischen Inventar-QR. Geräte erhalten den vorhandenen stabilen Bestandscode; Geräteträger benötigen einen nicht sprechenden, stabilen Scan-Token. QR-Inhalte enthalten keine Namen, Gesundheitsdaten oder sonstigen Fachinformationen.

## 5. Dienste, Ressourcen und Komponenten

### Neu bzw. wesentlich zu erweitern

- `WearerService`: Erstellen/Wiederaufnehmen eines Poolsatzes, Untersuchungsverlauf, Qualifikationen, manuelle Nachweise/Belege, Deaktivierung und Standortberechtigungen; alle Änderungen validiert und transaktional.
- `AtemschutzEquipmentService`: eigenständige Atemschutz-Stammdaten, Werkstattübergabe/-rückgabe, Status-/Ortshistorie, Komponenten, Fristen und Kennzeichnungsaktionen.
- `ReadinessService`: nur Datenzugriff/Mapping an neue Untersuchungshistorie und neue zentrale Akte anpassen; fachliche Statusformel nicht duplizieren.
- `WearerResource` und Relation Manager: Übersicht/Filter und vollständige Geräteträgerakte; bereit/nicht bereit, Frist läuft ab, G26.3 abgelaufen, Qualifikationen, Historie.
- Atemschutzgeräte-Ressource und Geräteakte: Listenfilter nach Typ, Standort, Lagerort, Status, Hersteller, Inventar-/Gerätenummer, Seriennummer und fälliger Prüfung; geschachtelte Eigenschaften, Komponenten, Fotos, QR/RFID, Dokumente, Prüf- und Statusverlauf.
- Gerätespezifische Aktionen „In Werkstatt geben“ und „Aus Werkstatt zurück“. Übergabe speichert Zielort, Status `in_repair`, Grund, Akteur und Zeitpunkt in einer Transaktion. Rückgabe verlangt Zielort und ausdrückliche Statusbestätigung; sie setzt nie stillschweigend `available`/„OK“.
- Das vorhandene `AtemschutzServiceProvider`/`AtemschutzPlugin` registriert einen eigenen Verwaltungsbereich mit Ressourcen, Routen, Policies/Gates, Navigation und Scan-Auflösung. Alle Atemschutzgeräteaktionen werden dort durch die standortübergreifende Atemschutzgerätewart-Rolle geschützt. Die erwarteten Oberflächen bleiben deutsch.

### Bestehende Oberflächen, die weiterverwenden

- `Overview`, `Board`, `MyStatus`, `MonitorService`, `AtemschutzPdf` und operative Livewire-Views bleiben die Überwachung. Die Personenauswahl liest direkt aus `as_wearers` und verwendet weiterhin die zentrale Bereitschaftsberechnung.
- Das Board kann neben dem Namen einen knappen Bereitschaftshinweis zeigen; administrative Untersuchungsunterlagen werden nicht in die operative Ansicht kopiert oder dort editierbar.
- Credential-, Personalakte-, Badge-, Dashboard-, Reminder-, Einsatzbericht- und Jahresbericht-Registrierungen bleiben angeschlossen und werden mit derselben zentralen Identität versorgt.
- Atemschutzgeräte-Listen und QR-Scans liegen im Atemschutzbereich und können die vorhandene `/i/{code}`-Route und `ScanRegistry` verwenden, sofern das Ziel sicher zur Atemschutzakte auflöst. `App\Support\QrCode` kann als SVG für HTML/PDF wiederverwendet werden; für PNG-Download ist die bestehende QR-Unterstützung gezielt um einen PNG-Writer zu ergänzen oder die bereits vorhandene Exportkonvention zu nutzen. Etiketten/PDFs bleiben innerhalb der berechtigten Oberfläche.

## 6. Prüfungen und Wartung

- Prüfungen und Wartung erhalten keinen zusätzlichen Navigationsbereich neben der Geräteakte.
- Atemschutz erhält eigene Prüfvorlagen, fällige Aufgaben, Checklisten, Prüferqualifikationen, interne/externe Prüfer, Signaturen, Kosten und unveränderliche Prüfprotokolle im Atemschutzmodul. `WartungService` wird nicht auf Atemschutzgeräte erweitert.
- Allgemeine `wm_protocols` bleiben unverändert bei ihren `ar_equipment`-Geräten. Da keine produktiven Atemschutzgeräte in diesem Bestand existieren, ist weder eine Übernahme noch eine Anzeige allgemeiner Altprotokolle in der Atemschutzakte vorgesehen.
- Eine Frist wird ausschließlich manuell je Gerät aus einer geprüften Hersteller-/Fachangabe übernommen und dokumentiert. Eine leere oder ungeklärte Grundlage bedeutet „Frist fehlt/zu klären“, nicht einen geratenen Ablaufzeitraum; es gibt keine automatische Typ-/Hersteller-Intervallberechnung.
- Reparatur und Werkstattstatus verändern technische Verfügbarkeit, nicht organisatorische Standortzugehörigkeit. Rückkehr aus einer Werkstatt ist kein Bestehensnachweis. Ein unterschrittener Mindestfüllstand sperrt die Einsatzbereitschaft unabhängig vom sonstigen technischen Gerätestatus. Die gepflegten Grenzwerte gelten je Flaschentyp; Anpassungen daran müssen nachvollziehbar protokolliert werden.

## 7. Rollen- und Objektberechtigungen

| Rolle | Geräteträgerakte | Atemschutzausrüstung | Überwachung |
|---|---|---|---|
| Admin | alle Standorte | alle Standorte und Kennzeichnungen | alle, einschließlich unzugeordneter Historie |
| Atemschutzgerätewart (`atemschutzwart`) | bestehender Zugriff bleibt erhalten | alle Atemschutzgeräte, Prüfungen, Historie und Kennzeichnungen standortübergreifend | bestehender Zugriff bleibt erhalten |
| Wehrführung/allgemeine Gerätewarte | nur bisher zulässige Geräteträgerdaten nach Standortrecht | keine Atemschutzgeräteverwaltung; allgemeine Verwaltung blendet Atemschutzgeräte aus | nur bisher zugängliche Standorte |
| Einsatzkräfte mit Atemschutz-Modulzugang | kein Administrationszugriff | kein Administrationszugriff | bisherige operative Berechtigung |

Der bestehende globale Rollenwert `atemschutzwart` bleibt erhalten und wird in der Oberfläche als „Atemschutzgerätewart“ bezeichnet bzw. entsprechend erweitert. Er umfasst die bisherige Atemschutzberechtigung und zusätzlich die standortübergreifende Verwaltung der Atemschutzgeräte. Die standortbezogene allgemeine Geräteverwaltung und die Wehrführung erhalten diese Rechte nicht; Admins behalten den Vollzugriff.

Eine neue Ressource darf nicht nur durch Filament-Navigation geschützt werden. Resource-Query, Detail-URL, Form-/Bulk-Aktion, Livewire-Methode, Dokumentroute, Scan-Ziel und Download müssen dieselbe passende Gerätewart- bzw. personenbezogene Rollenprüfung durchführen. Nicht zugeordnete Altgeräte werden nicht aus aktuellen Benutzerstandorten geraten oder automatisch lokalen Nutzern sichtbar gemacht.

## 8. QR- und RFID-Abläufe

### QR-Code

- Inventargegenstände nutzen ihren stabilen Bestandscode; Geräteträger erhalten einen nicht sprechenden Token ohne personenbezogene oder medizinische Nutzdaten.
- Eine zentrale Atemschutz-Label-/Scan-Komponente erzeugt URLs auf den geschützten Detailpfad, stellt SVG/PNG-Download und eine druckbare PDF-Etikettenausgabe bereit und verwendet `App\Support\QrCode`.
- Der vorhandene `/i/{code}`-Resolver verweist auf eine interne Detailseite. Beim Zielaufruf werden Anmeldung, aktiver Modulstatus und Objekt-/Standortrecht erneut geprüft. Ein QR-Code ist kein Zugriffstoken.
- Der bereits im Atemschutzpass angezeigte befristete Nachweis-QR bleibt gesondert. Ein statischer Geräte-QR gibt keine Bereitschafts-, G26.3-, Dokument- oder Personendaten preis.

### RFID

- Tags sind zunächst manuell erfassbar; ein USB-/Bluetooth-Leser, der UID und Enter sendet, funktioniert ohne Hardware-Sonderlogik. Browser-NFC kann später als Adapter hinzukommen.
- Mehrere aktive Tags pro Objekt sind möglich. Eine global eindeutige aktive UID verhindert, dass derselbe Chip gleichzeitig einer Person und einem Gegenstand oder mehreren Gegenständen gehört.
- Deaktivierung/Neuzuordnung markiert die frühere Zuweisung samt Akteur und Zeitpunkt als Historie; Datensätze werden nicht überschrieben oder gelöscht.
- `Board::addByRfid()`, `Equipment::findByScan()`, `EquipmentService::setTag()`, Bestandssuche und Geräte-Scan müssen auf die festgelegte Datenquelle umgestellt werden; allgemeine Ausrüstungslisten/-aktionen schließen Atemschutzprofile aus und Scans leiten berechtigungssicher in den Atemschutzbereich weiter. Die Normalisierung wird vereinheitlicht und durch Eindeutigkeitsconstraints abgesichert.

## 9. Rückwärtskompatibilität und Datenschutz

- Bestehende `as_operations`, `as_teams`, `as_team_members`, `as_readings`, `as_warnings`, `as_log`, `as_trainings` und Einsatzbericht-Verknüpfungen bleiben bestehen. Keine Tabellen werden neu aufgebaut oder rückwirkend aus aktuellen Mitgliedsdaten „korrigiert“.
- `as_wearers` bleibt je Benutzer eindeutig. Wiederaufnahme eines früheren Geräteträgers aktualisiert denselben Pooldatensatz und erzeugt keinen zweiten. Der bestehende globale Atemschutzwart behält seinen bisherigen Zugriff auf diese Geräteträgerdaten und erhält zusätzlich die standortübergreifende Geräteverwaltung.
- Migrationsreihenfolge: additive Tabellen/Spalten und Constraints; Datenübernahme vorhandener G26-/RFID-Werte; Codeumstellung und Tests; erst nach bestätigter Übergangsphase Entfernung entbehrlicher Altspalten. Down-Migrationen dürfen Historien nicht stillschweigend vernichten.
- Neue Atemschutzgeräte erhalten bei der Anlage ausdrücklich einen organisatorischen Standort und einen physischen Lagerort. Diese Werte werden nicht aus dem Standort des gerade verwaltenden Benutzers abgeleitet.
- G26.3-Datumsangaben sind besonders geschützt: keine Befunde, Diagnosen oder Nachweisdateien speichern; keine Untersuchungsdaten oder Gültigkeitswerte in allgemeinen Logs, Mailbetreffs oder QR-Codes. Gesundheitsdaten sind bei `ActivityRecorder` zu maskieren oder die entsprechenden Modelle dort auszunehmen; eine erforderliche Fachhistorie speichert nur Änderungstyp, Objekt, Akteur und Zeitpunkt.
- **Lösch-/Übertragungsrisiko:** `MemberRemoval` überträgt standardmäßig nicht-kaskadierende Fremdschlüssel an eine Ersatzperson; bestehende Atemschutz-FKs sind teils kaskadierend. G26.3-Untersuchungs- und Gültigkeitsdaten werden beim endgültigen Löschen des Mitgliedskontos mitgelöscht und niemals an eine Ersatzperson übertragen. Die Umsetzung muss das bestehende Verfahren explizit berücksichtigen und dies mit „Löschen mit Übertragung“ testen.
- Gespeicherte Dateien werden über `App\Support\FileResponse::make()` ausgeliefert und anhand erlaubter Dateiendungen validiert; kein MIME-Vertrauen aus Upload-Metadaten.

## 10. Tests und Qualitätssicherung

Ergänzung der bestehenden Pest-Struktur, insbesondere `tests/Feature/Atemschutz/AtemschutzTest.php`, mit separaten Tests für Ressourcen, Services und Migrationen:

1. **Identität und Readiness:** exakt ein Wearer je Benutzer; keine Kopie von Benutzername/Standort; bestehende `ReadinessService`-Ergebnisse für G26.3, AGT, CSA, 12-Monatsnachweise, Lehrgänge, Unterweisungen und Einsatzbericht bleiben über Alt-/Neudaten gleich.
2. **Historie:** Backfill aktueller Untersuchungsdaten; neuer/abgelaufener G26.3-Verlauf; ausschließlich Datumsangaben, keine Befunde, Diagnosen oder Dateien; Datumsvalidierung.
3. **Qualifikationen:** eigene AGT-/CSA-Qualifikationen, Ablaufdaten und optionale private Belege; abgeschlossene Lehrgänge und Unterweisungen bleiben ihre maßgeblichen Quelldaten und werden nicht dupliziert. Quellen ohne Abschlussdatum werden entsprechend der vorhandenen Readiness-Regel behandelt.
4. **Standort-/Rollenisolation:** Admin, globaler Atemschutzgerätewart mit bisherigem Geräteträgerzugriff und neuer Geräteverwaltung, Wehrführung mit ihrem einen Standort, allgemeine Gerätewarte, normale Einsatzkraft, inaktiver Standort und direkter URL-/Livewire-/Download-Aufruf.
5. **Atemschutzausrüstung:** leer startender eigener Bestand und eigene Verwaltungsoberfläche; keine Atemschutzgeräte in allgemeinen Ausrüstungslisten/-aktionen; keine Übernahme allgemeiner Geräte oder Wartungsdaten; standortübergreifende Gerätewartberechtigung; Typdetails, Komponenten, physischer Ort und Fristen.
6. **Status/Verlauf:** Werkstattübergabe und -rückgabe sind atomar; Akteur/Datum/Grund/alte und neue Werte bleiben nachvollziehbar; Rückgabe setzt nicht automatisch „OK“; Defekt, Füllstand genau am Mindestwert, unterschrittener Mindestfüllstand, leerer Zylinder, in Reparatur und ausgesondert beeinflussen Verfügbarkeit nachvollziehbar.
7. **Prüfung/Wartung:** hersteller-/gerätespezifische Frist, fehlende Grundlage, bestandene/nicht bestandene Prüfung, eigene Atemschutz-Prüfprotokolle und deren Unveränderlichkeit, Status-/Schadensablauf.
8. **QR/RFID:** stabile QR-Ziele, keine sensiblen QR-Inhalte, Zielautorisierung, PNG/PDF-Ausgabe, UID-Normalisierung, aktive Eindeutigkeit über beide Objektarten, mehrere Tags, Deaktivierung und Neuzuordnung samt Historie.
9. **Regression:** Teamregistrierung per RFID/Pool, Override mit Begründung, CSA-Bereitschaft, laufende Warnungen, PDF, Einsatzberichtbesatzung, Personalakte, Credential-QR, Erinnerungen und Jahresbericht.
10. **Datenlebenszyklus:** `MemberRemoval` darf keine Atemschutzhistorie auf eine andere Person übertragen; Tests für erlaubte Statusänderung, Deaktivierung und Löschverhalten.

PostgreSQL bleibt zwingend auch für Tests; Constraints, partielle eindeutige Indizes, Transaktionen und Migrationen müssen gegen PostgreSQL geprüft werden. Danach die gezielten Atemschutz-/Ausrüstung-/Wartungstests, `phpstan`, Pint und – sofern verfügbar – die Gesamttestsuite ausführen. Die Dokumentation `docs/MODULE.md` ist nach Freigabe um die tatsächliche Benutzerfunktion zu ergänzen; `docs/LOCATIONS.md` und `CLAUDE.md` nur, wenn Rechte-/Standortverhalten oder Modulabhängigkeiten angepasst werden.

## 11. Implementierungsreihenfolge nach Freigabe

1. **Berechtigungsmodell umsetzen:** bestehenden Rollenwert `atemschutzwart` sichtbar als „Atemschutzgerätewart“ führen und die standortübergreifende Geräteverwaltung ergänzen; bestehende Geräteträger- und Überwachungsrechte erhalten. Löschverhalten für G26.3-Datumsangaben absichern.
2. **Datenmodell und Migrationsschutz:** Untersuchungshistorie, eigenständiger leer startender Gerätebestand/-typdetails, optionale Komponentenzuordnung, RFID-Zuweisungshistorie und Dokumente; Constraints und Rollback-Schutz. Kein Geräte-/Wartungsdaten-Backfill.
3. **Geräteträgerakte:** `WearerService`, Historie und Nachweise, Ressourcen/Filter; die `ReadinessService`-Berechnung weiterverwenden und den bestehenden Pool nahtlos auf die neue Oberfläche führen.
4. **Atemschutzverwaltung anbinden:** leer startender `as_equipment`-Bestand, eigene Oberfläche und standortübergreifende Atemschutzgerätewart-Autorisierung; Stammdaten, drei Gerätetypen, Komponenten sowie Geräte-/Werkstattorte.
5. **Werkstatt- und Verlaufsvorgänge:** transaktionale Übergabe/Rückgabe und Historisierung im Atemschutz-Geräteverlauf; Status und Frist getrennt darstellen.
6. **Prüfungen und Dokumente:** eigene Atemschutz-Prüfvorlagen, Aufgaben, Signaturen und unveränderliche Protokolle; manuell gepflegte, belegte Fristen je Gerät ohne automatische Typ-/Herstellerintervalle.
7. **Kennzeichnungen:** RFID-Zuweisungsmodell und gemeinsame Auflösung; stabile QR-Scan-Ziele, SVG/PNG und Etiketten-PDF.
8. **Überwachung und übrige Integrationen:** Board-Auswahl und knappe Statushinweise, Personalakte, Einsatzbericht, Badge, Dashboard, Erinnerungen und PDF regressionssicher anbinden.
9. **Berechtigungshärtung, Tests und Dokumentation:** negative Tests aller direkten Pfade, `MemberRemoval`, Postgres-Migrationen, Testläufe und Aktualisierung der Nutzerdokumentation.

## 12. Wiederverwendung, Erweiterung und neue Strukturen

| Entscheidung | Bestehende Struktur |
|---|---|
| **Wiederverwenden** | `users` für Identität/Standort; `as_wearers` als einzige Poolakte; `as_trainings`; `ReadinessService`; `MonitorService`; `as_team_members`/`as_operations`; `AtemschutzPdf`; `ScanRegistry`, `/i/{code}`, `App\Support\QrCode` und die bestehenden Registries.
| **Erweitern** | Atemschutzbereich in der UI mit exklusiver Geräteverwaltung; standortübergreifende Atemschutzgerätewart-Autorisierung; Geräteträgeruntersuchungen und Qualifikationsansicht; Geräte- und RFID-Suchpfade; allgemeine Kategorienauswahl um das Entfernen der Atemschutzkategorien.
| **Neu, fachlich begründet** | eigenständige Atemschutz-Gerätestammdaten samt Komponenten, Prüfungen, Mängeln und Verlauf; typgebundene Atemschutzdetails; G26.3-Untersuchungshistorie; eigene Prüfvorlagen, Aufgaben und signierte Atemschutzprotokolle; nachvollziehbare Kennzeichnungszuweisungen; private Geräte-/Nachweisdateien, soweit keine bestehende Relation passt.
| **Nicht neu anlegen** | zweite Personen-/Geräteträgertabelle; parallele Standortverwaltung; stille Umschreibung/Änderung signierter Wartungsprotokolle; eigenständige QR-/RFID-Navigation; kopierte Lehrgangs- oder Unterweisungsdatensätze; zweite Bereitschaftsberechnung.

## 13. Festlegungen und verbleibende Risiken

1. **Datenbasis:** Es gibt derzeit keine produktiven Atemschutzgeräte in der allgemeinen Geräteverwaltung. Der Atemschutzbestand startet daher leer; es gibt keinen Import, Backfill oder Crosswalk aus `ar_equipment` oder `wm_protocols`. Diese allgemeinen Bestandsdaten bleiben unverändert.
2. **Löschverhalten:** Es werden keine G26.3-Belege/Dateien gespeichert. Beim endgültigen Löschen eines Mitgliedskontos werden die zugehörigen Untersuchungs- und Gültigkeitsdaten mitgelöscht; sie dürfen nie mit `MemberRemoval` auf eine Ersatzperson übertragen werden.
3. **Prüffristen:** Fristen werden ausschließlich manuell je Gerät anhand geprüfter Hersteller-/Fachangaben festgelegt; automatische Standardintervalle nach Gerätetyp oder Hersteller sind ausgeschlossen.
4. **Ausrüstungsstatus und Flaschenfüllstand:** technischer Gerätestatus und Füllstand sind getrennte Merkmale. Der Atemschutzgerätewart pflegt den vorgeschriebenen Mindestfüllstand je Flaschentyp. Bei Unterschreitung oder leerer Flasche wird die Einsatzbereitschaft automatisch gesperrt; der exakte Mindestwert bleibt füllstandsseitig einsatzfähig. Der Füllstand ersetzt keine technische Prüfung.
5. **Atemschutzpass vs. Geräte-QR:** der bestehende befristete, extern prüfbare Pass darf nicht mit einem statischen QR verwechselt werden, der auf eine interne Akte oder Gesundheitsdokumente führt.

## 14. Umsetzungsstand

Die Umsetzung erfolgte nach ausdrücklicher Freigabe. Der freigegebene Kernumfang ist umgesetzt:

- einen leer startenden `as_equipment`-Bestand mit Atemschutzmasken, Pressluftatmern, Stahl-/Composite-Flaschen und Chemikalienschutzanzügen; keine Übernahme aus `ar_equipment` oder `wm_protocols`;
- eine eigene Atemschutz-Verwaltung im Filament-Bereich mit standortübergreifender Prüfung der bestehenden Rolle `atemschutzwart`, Gerätekomponenten, RFID/QR, manuell dokumentierter Prüffristen und Wartungsaufgaben;
- zentrale Mindestfülldruckwerte je Flaschentyp ohne erfundene Voreinstellung; fehlende Werte, leerer Füllstand und Unterschreitung sperren die Einsatzbereitschaft, genau am Mindestwert ist der Füllstand ausreichend;
- signierte, datenbankseitig unveränderliche Prüfprotokolle sowie explizite Werkstattübergabe und bestätigte Rückgabe;
- Ausblendung und Schreibschutz der Atemschutz-Kategorien in der allgemeinen Ausrüstungsverwaltung, ohne vorhandene Geräte- oder Wartungsdaten zu löschen;
- G26.3-Verlauf mit ausschließlich Untersuchungs- und Gültigkeitsdatum. Vorhandene Einzelwerte werden beim Upgrade übernommen; neue Einträge werden append-only erfasst, `ReadinessService` wertet den neuesten Eintrag aus und `MemberRemoval` löscht diese Daten mit dem Mitglied statt sie zu übertragen;
- gemeinsame Kennzeichnungszuweisungen für Geräteträger und Atemschutzgeräte mit Normalisierung, global eindeutiger aktiver Kennung, mehreren Tags je Objekt und nachvollziehbarer Deaktivierung/Neuzuordnung. Vorhandene Atemschutz-RFID-Werte werden als gekennzeichnete Übernahmen erfasst; Board und Geräte-Scan lesen nur noch die zentrale Tabelle;
- private, auf 25 MB begrenzte Atemschutz-Gerätedokumente mit Erweiterungsprüfung und geschützter Auslieferung über `FileResponse`;
- deutsche Nutzerdokumentation sowie direkte Berechtigungs-, Scan-, Datei-, G26.3-Lösch- und Ausrüstungsregressionstests. Manuelle Atemschutz-Nachweise bleiben in `as_trainings`; Lehrgänge und Unterweisungen bleiben in ihren jeweiligen Modulen und werden nicht dupliziert.

Die Gesamtsuite lief mit PostgreSQL 18 und sechs parallelen Prozessen erfolgreich: 2.445 Tests und 16.092 Assertions. PHPStan über das gesamte Projekt und Pint für alle geänderten PHP-Dateien sind ebenfalls erfolgreich. Für den lokalen Paralleltest war ein temporärer PostgreSQL-Testserver mit `max_locks_per_transaction=256` nötig, weil der Standardwert 64 beim frischen Zurücksetzen des vollständigen Schemas nicht ausreichte. Die Tag- und G26.3-Migration ist additiv abgesichert: Ein Rollback wird abgebrochen, wenn mehrere historische Einträge nicht verlustfrei in die früheren Einzelwertfelder zurückgeschrieben werden könnten. Allgemeine Ausrüstungsdaten und signierte `Wartung`-Protokolle werden nicht migriert oder verändert.

Der weitere Ausbau der Qualifikationsakte ist umgesetzt: AGT-/CSA-Einzelwerte werden bei der Migration verlustfrei als Historieneinträge übernommen; neue manuelle Qualifikationen können mehrfach, mit optionalem Gültigkeitsdatum, Aussteller, Notiz und privatem Beleg erfasst werden. Die Bereitschaft nutzt jeweils den neuesten manuellen Eintrag, berücksichtigt weiterhin abgeschlossene AGT-Lehrgänge (auch ohne eingetragenes Abschlussdatum) und bleibt von den Daten in `Lehrgänge` und `Unterweisungen` getrennt. AGT-/CSA-Ablaufdaten werden in der bestehenden Erinnerungslogik berücksichtigt. Ein Rollback wird abgebrochen, sobald neuere Qualifikationsdaten nicht vollständig auf die alten Einzelfelder abbildbar sind. Die gezielten Atemschutz-, Geräte-, Personalakten-, Unterweisungs- und Standortbenachrichtigungstests laufen ohne Fehler; PHPStan ist erfolgreich.
