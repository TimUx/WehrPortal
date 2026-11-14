# Abläufe

[Zur Hauptdokumentation](../README.md) · [Berechtigungsmatrix](BERECHTIGUNGEN.md) · [Kernfunktionen](KERNFUNKTIONEN.md) · [Module](MODULE.md)

Die wichtigsten Abläufe in WehrPortal als Diagramm: wer was wo tut und was danach passiert. Jeder Abschnitt nennt zuerst **wer** und **wo**, dann folgt der Ablauf. Die Einzelheiten stehen in [KERNFUNKTIONEN.md](KERNFUNKTIONEN.md) und [MODULE.md](MODULE.md), die Rechte in der [Berechtigungsmatrix](BERECHTIGUNGEN.md).

Die Diagramme sind in Mermaid geschrieben; GitHub zeigt sie direkt an.

## Inhalt

**Kern**

- [Anmelden mit Login-Link](#anmelden-mit-login-link)
- [Neuen Kameraden aufnehmen](#neuen-kameraden-aufnehmen)
- [Modul einschalten und freischalten](#modul-einschalten-und-freischalten)
- [Wehrführung eines Standorts wechseln](#wehrführung-eines-standorts-wechseln)
- [Kamerad verlässt die Wehr](#kamerad-verlässt-die-wehr)

**Module**

- [Fahrzeugbuchung: Buchung und Konflikt](#fahrzeugbuchung-buchung-und-konflikt)
- [Einsatzbericht: vom Alarm bis zur Freigabe](#einsatzbericht-vom-alarm-bis-zur-freigabe)
- [Lehrgänge: vom Interesse zum absolvierten Lehrgang](#lehrgänge-vom-interesse-zum-absolvierten-lehrgang)
- [Führerscheine: Kontrolle per Foto](#führerscheine-kontrolle-per-foto)
- [Unterweisungen: durchführen und unterschreiben](#unterweisungen-durchführen-und-unterschreiben)
- [Kalender: Termin, Rückmeldung, Anwesenheit](#kalender-termin-rückmeldung-anwesenheit)
- [Mitteilungen: Lesebestätigung](#mitteilungen-lesebestätigung)
- [Schichtplan: Selbst-Eintragung](#schichtplan-selbst-eintragung)
- [Mängelmanagement: vom Melden bis erledigt](#mängelmanagement-vom-melden-bis-erledigt)
- [Fahrzeuge: Prüfung und Mangel](#fahrzeuge-prüfung-und-mangel)
- [Fahrtenbuch: Fahrt und Korrektur](#fahrtenbuch-fahrt-und-korrektur)
- [Kleiderkammer: Ausgabe und Wäsche](#kleiderkammer-ausgabe-und-wäsche)
- [Schlüssel: Ausgabe mit Quittung](#schlüssel-ausgabe-mit-quittung)
- [Finanzen: Ausgabe einreichen und genehmigen](#finanzen-ausgabe-einreichen-und-genehmigen)
- [Verbandbuch: Eintrag und Nachtrag](#verbandbuch-eintrag-und-nachtrag)
- [Personalverwaltung: Änderungswunsch](#personalverwaltung-änderungswunsch)
- [Vereinsverwaltung: Beitritt und Vorstand](#vereinsverwaltung-beitritt-und-vorstand)

---

## Kern

### Anmelden mit Login-Link

**Wer:** jeder aktive Kamerad mit E-Mail-Adresse. **Wo:** `/anmelden`.

```mermaid
flowchart TD
    A["Kamerad gibt seine E-Mail-Adresse ein"] --> B{"Adresse bekannt<br>und Kamerad aktiv?"}
    B -- nein --> C["Dieselbe Meldung wie bei bekannten Adressen,<br>keine Mail"]
    B -- ja --> D["Mail mit Login-Link<br>15 Minuten gültig, nur einmal"]
    D --> E["Link öffnen: Seite mit Knopf „Anmelden“"]
    E --> F["Klick auf „Anmelden“ löst den Link ein"]
    F --> G["Angemeldet, Sitzung bleibt 90 Tage"]
    E -. "Mail-Scanner öffnet nur die Seite" .-> H["Link bleibt gültig"]
```

Anfragen sind je Adresse (5 pro Stunde) und je IP (20 pro Stunde) begrenzt. Mitglieder ohne E-Mail-Adresse können sich nicht anmelden.

### Neuen Kameraden aufnehmen

**Wer:** globaler Admin unter Admin → Kameraden, oder wer die Personalakten verwaltet unter Personalverwaltung → „Neue Mitglieder“ (auch viele auf einmal per CSV).

```mermaid
flowchart TD
    A["Kamerad anlegen:<br>Name, Abteilungen, Standort, Status aktiv"] --> B{"E-Mail-Adresse<br>eingetragen?"}
    B -- nein --> C["Mitglied ohne Login<br>in allen Modulen wählbar, meldet sich nie an"]
    B -- ja --> D["Einladung erzeugen<br>7 Tage gültig, nur einmal"]
    D --> E["QR-Code in der Wache scannen"]
    D --> F["„Per E-Mail schicken“"]
    E --> G["Seite mit Knopf „Anmelden“"]
    F --> G
    G --> H["Erster Login führt zu „Willkommen“"]
    A --> I["Module mit „automatisch freischalten“<br>sind sofort freigeschaltet"]
    I --> J["Weitere Module und Rollen:<br>Admin → Module, Admin → Standardrollen"]
```

### Modul einschalten und freischalten

**Wer:** globaler Admin. **Wo:** Admin → Module.

```mermaid
flowchart TD
    A["Admin → Module: Schalter „Aktiv“"] --> B{"Für alle Kameraden?"}
    B -- ja --> C["„Neue Kameraden automatisch freischalten“"]
    B -- nein --> D["„x Kameraden“: einzelne Kameraden freischalten"]
    C --> E["Kameraden sehen das Modul<br>auf Startseite und im 3×3-Menü"]
    D --> E
    D --> F["Im selben Fenster: klassische Rolle vergeben<br>gilt für die ganze Kommune"]
    A --> G["Mit Standorten: Wehrführung und andere Standardrollen<br>bekommen ihre Rolle im Modul von selbst"]
    H["Modul ausschalten"] --> I["Seiten geben 404, Rollen ruhen,<br>Daten bleiben erhalten"]
```

### Wehrführung eines Standorts wechseln

**Wer:** nur ein globaler Admin. Die Wehrführung selbst, Standort-Admins und GBI/SBI können das nicht. **Wo:** Admin → Standardrollen.

```mermaid
flowchart TD
    A["Admin → Standardrollen"] --> B{"Ist der neue Wehrführer<br>aktives Mitglied des Standorts?"}
    B -- nein --> C["Zuerst dem Standort hinzufügen:<br>Admin → Standorte → Mitglieder<br>oder Admin → Kameraden"]
    C --> D
    B -- ja --> D["Neue Vergabe: Kamerad, Standardrolle „Wehrführung“,<br>Standort, Begründung;<br>optional „Gültig bis“ und „Stellvertretung“"]
    D --> E["Der neue Wehrführer hat sofort alle Rechte<br>am Standort und ist dort Standort-Admin"]
    E --> F["Bisherige Vergabe: „Widerrufen“ mit Widerrufsgrund"]
    F --> G["Der bisherige Wehrführer verliert die Rechte sofort.<br>Was er angelegt hat, bleibt bestehen"]
    G --> H["Beide Vergaben bleiben mit Zeit, Admin und Grund<br>in der Liste sichtbar"]
```

Was dabei gilt:

- **Reihenfolge:** Erst neu vergeben, dann die alte Vergabe widerrufen. So ist der Standort nie ohne Wehrführung. Mehrere Kameraden können die Rolle am selben Standort gleichzeitig haben.
- **Vergaben werden nicht geändert:** Kamerad, Standort, „Gültig bis“ und „Stellvertretung“ stehen nach dem Speichern fest. Wird der Stellvertreter zum Wehrführer, wird seine Vergabe widerrufen und ohne „Stellvertretung“ neu angelegt.
- **Befristete Vertretung:** Vergabe mit „Stellvertretung“ und „Gültig bis“; sie endet von selbst.
- **Kein eigener Schritt je Modul:** Die Rolle bringt die Rechte in allen Modulen mit, siehe [Berechtigungsmatrix](BERECHTIGUNGEN.md#standardrollen-führung-von-standort-und-kommune). Klassische Modulrollen des bisherigen Wehrführers (unter Admin → Module vergeben) bleiben davon unberührt und müssen bei Bedarf dort entfernt werden.
- **Benachrichtigungen** an die Wehrführung des Standorts gehen ab der Vergabe an den neuen Inhaber.
- **Verlässt der Wehrführer den Standort** oder wird er inaktiv, ruht die Rolle sofort, auch ohne Widerruf.

Für alle anderen Standardrollen (Gerätewart, Jugendwart, GBI/SBI …) ist der Ablauf derselbe; Jugend- und Kinderwart brauchen zusätzlich die Abteilung.

### Kamerad verlässt die Wehr

**Wer:** globaler Admin. **Wo:** Admin → Kameraden.

```mermaid
flowchart TD
    A["Kamerad verlässt die Wehr"] --> B{"Sollen seine Einträge<br>erhalten bleiben?"}
    B -- "ja, Normalfall" --> C["Status „ausgetreten“"]
    C --> D["Kein Login mehr, alle Rollen ruhen,<br>Buchungen, Einsätze und Akte bleiben"]
    B -- "nein, wirklich löschen" --> E["„Löschen mit Übertragung“"]
    E --> F["Fenster zeigt je Bereich die Zahl der Einträge"]
    F --> G["Kamerad wählen, der alles übernimmt"]
    G --> H["Übertragen und löschen: alles oder nichts"]
    H --> I["Logs bleiben unverändert; Verbandbuch und<br>Expositionen werden nur von der Person gelöst"]
```

---

## Module

### Fahrzeugbuchung: Buchung und Konflikt

**Wer:** jeder mit Modulzugang bucht; Entscheider (mit Standorten auch die Wehrführung des Fahrzeug-Standorts) entscheiden Konflikte, ohne Entscheider die Admins. **Wo:** `/fahrzeugbuchung`.

```mermaid
flowchart TD
    A["Kamerad wählt Fahrzeug und Zeitraum"] --> B{"Fahrzeug gesperrt?<br>Werkstatt, Ausfall"}
    B -- ja --> X["Buchung nicht möglich"]
    B -- nein --> C{"Überschneidung mit<br>bestätigter Buchung?"}
    C -- nein --> D["Buchung bestätigt"]
    C -- ja --> E["„Zur Entscheidung vorlegen“:<br>Anfrage wartet"]
    E --> F["Entscheider werden benachrichtigt"]
    F --> G{"Entscheidung"}
    G -- "Anfrage bekommt den Vorrang" --> H["Anfrage bestätigt,<br>bisherige Buchung unterlegen"]
    G -- "bisherige Buchung bleibt" --> I["Anfrage abgelehnt"]
    E -- "Kamerad zieht zurück" --> J["Anfrage zurückgezogen"]
    H --> K["Gibt der Gewinner den Zeitraum frei,<br>wird die unterlegene Buchung<br>von selbst wieder bestätigt"]
    D --> L["Kurz vor dem Ende: Erinnerung mit<br>„Fahrzeug ist schon zurück“"]
```

Doppelbuchungen verhindert zusätzlich die Datenbank. Nach Beginn lässt sich eine Buchung nicht mehr ändern oder stornieren, nur vorzeitig beenden.

### Einsatzbericht: vom Alarm bis zur Freigabe

**Wer:** jeder Kamerad mit Modulzugang schreibt; die Wehrführung des Standorts, GBI/SBI und Admins geben frei. **Wo:** `/einsatzbericht`.

```mermaid
stateDiagram-v2
    [*] --> Entwurf: Kamerad legt an („+ Neuer Einsatz“)
    [*] --> Aktuell: Alarm aus DIVERA, noch ohne Verfasser
    Entwurf --> Aktuell: „Einsatz starten“
    Aktuell --> Aktuell: Kamerad übernimmt den Bericht
    Aktuell --> Abgeschlossen: Verfasser schließt ab (Pflichtfelder, optional Unterschrift)
    Abgeschlossen --> Freigegeben: Wehrführung gibt frei (Prüfsumme über den Inhalt)
    Abgeschlossen --> Abgeschlossen: Wehrführung korrigiert
    Freigegeben --> [*]
```

- Solange der Einsatz läuft, senden alle mit Modulzugang Lagemeldungen.
- Nach dem Abschluss ist der Verfasser draußen: Den Bericht öffnen nur noch Wehrführung des Standorts, GBI/SBI, Admins und die Rolle Einsicht.
- Erst freigegebene Berichte zählen in Jahresstatistik und Jahresbericht (abschaltbar).
- Eine Erinnerung geht an die zuständige Wehrführung, wenn Berichte auf die Freigabe warten.

### Lehrgänge: vom Interesse zum absolvierten Lehrgang

**Wer:** Kamerad meldet Interesse; Ausbildungsleiter, mit Standorten auch die Wehrführung für ihre Kameraden, bearbeiten. **Wo:** `/lehrgaenge`, „Anmeldungen“.

```mermaid
flowchart TD
    A["Kamerad: „Interesse melden“"] --> B["Ausbildungsleiter und Wehrführung des Standorts<br>bekommen Mail und Glocke"]
    A2["Ausbildungsleiter oder Wehrführung:<br>„Kameraden zu einem Lehrgang anmelden“"] --> B
    B --> C["Anmeldung in fox112 von Hand"]
    C --> D["„In fox112 angemeldet“: Warteliste"]
    D --> E["Termin eintragen: bestätigt,<br>Kamerad bekommt alle Tage per Mail"]
    E --> F["14 Tage vorher: Erinnerung"]
    F --> G["Nach dem Lehrgang: absolviert<br>= Qualifikation"]
    A -- "Kamerad sagt ab" --> H["Absage: Ausbildungsleiter meldet in fox112 ab<br>und zeichnet ab"]
    G --> I{"Auffrischung nötig?"}
    I -- ja --> J["Erinnerung vor Ablauf;<br>neues Datum = Auffrischung"]
    K["Nachtragen ohne Anmeldung:<br>„absolvierten Lehrgang eintragen“"] --> G
```

### Führerscheine: Kontrolle per Foto

**Wer:** Kamerad reicht ein; Führerscheinprüfer, mit Standorten auch die Wehrführung für ihre Kameraden, prüfen. **Wo:** `/fuehrerscheine`, „Prüfen“.

```mermaid
flowchart TD
    A["Kamerad: Klassen, Ablaufdaten,<br>Fotos von Vorder- und Rückseite"] --> B["Prüfer wird benachrichtigt"]
    B --> C{"Stimmen Angaben<br>und Fotos überein?"}
    C -- ja --> D["„Stimmt – bestätigen“"]
    C -- nein --> E["„Zurückweisen“ mit Grund"]
    E --> A
    D --> F["Fotos werden gelöscht,<br>die Prüfung bleibt als Nachweis"]
    F --> G["Fahrzeuge und Fahrtenbuch wissen,<br>wer was fahren darf"]
    F --> H["Erinnerung 60 Tage vor Ablauf<br>und wenn die nächste Prüfung fällig ist"]
    H --> A
```

### Unterweisungen: durchführen und unterschreiben

**Wer:** Unterweisungsbeauftragte legen Arten an und führen durch; die Wehrführung führt für die Kameraden ihres Standorts durch. **Wo:** `/unterweisungen`.

```mermaid
flowchart TD
    A["Unterweisungsbeauftragter legt Art an:<br>Intervall, verpflichtend für wen"] --> B["„Durchführen“: Art, Datum, Unterweisender,<br>Teilnehmer, Inhalt, Dokumente"]
    B --> C["Teilnehmer unterschreiben nacheinander<br>auf dem Tablet"]
    C --> D["Übersicht: grün gültig, gelb läuft ab,<br>rot abgelaufen oder fehlt"]
    D --> E["Vor Ablauf: Hinweis an Kamerad<br>und Zuständige"]
    E --> B
    C --> F["Zählt die Art als Atemschutz-Unterweisung:<br>Atemschutz wertet sie mit"]
    C --> G["Einweisung auf ein Fahrzeug:<br>Fahrtenbuch warnt ohne Einweisung"]
```

### Kalender: Termin, Rückmeldung, Anwesenheit

**Wer:** Terminverwalter und Wehrführung für ihren Standort; Jugend- und Kinderwarte für die eigene Abteilung. **Wo:** `/kalender`.

```mermaid
flowchart TD
    A["Termin anlegen: Standort, Zielgruppe<br>aus Abteilungen, Funktionen, einzelnen Kameraden"] --> B["Zielgruppe bekommt Mail, Glocke und Push"]
    B --> C["Kamerad sagt zu oder ab"]
    B --> D["Erziehungsberechtigte bekommen eine Kopie<br>und antworten über einen Link"]
    C --> E["Wer nicht geantwortet hat,<br>bekommt einmal eine Erinnerung"]
    E --> F["Nach dem Termin: Anwesenheit eintragen"]
    F --> G["Stundennachweis und Dienstbeteiligung<br>in der Personalverwaltung"]
    A --> H["Absage: wer zu- oder vielleicht zugesagt hat,<br>bekommt eine Mail"]
```

### Mitteilungen: Lesebestätigung

**Wer:** Redakteure und Admins; mit Standorten Standort-Admins für ihren Standort. **Wo:** `/mitteilungen/verwalten`.

```mermaid
flowchart TD
    A["Mitteilung schreiben: Zielgruppe, Zeitraum,<br>wichtig, Anhänge, Lesebestätigung"] --> B{"Liegt heute im<br>Veröffentlichungszeitraum?"}
    B -- nein --> C["Kein Versand; später ausdrücklich anfordern"]
    B -- ja --> D["Startseite der Empfänger;<br>auf Wunsch Mail, Glocke, Push"]
    D --> E["Empfänger: „Gelesen und verstanden“"]
    E --> F["„Lesebestätigungen“:<br>x von y gelesen, z bestätigt"]
    F --> G["„Erinnern“ schickt sie genau denen,<br>die noch fehlen"]
    G --> E
```

### Schichtplan: Selbst-Eintragung

**Wer:** Planer legen an; Kameraden der Zielgruppe tragen sich ein. **Wo:** `/schichtplan`.

```mermaid
flowchart TD
    A["Planer: Plan mit Stationen,<br>„Mitglieder tragen sich selbst ein“"] --> B["Veröffentlichen: Zielgruppe wird benachrichtigt"]
    B --> C["Kamerad wählt freien Platz: „Eintragen“"]
    C --> D{"Genehmigung<br>eingeschaltet?"}
    D -- nein --> E["Platz bestätigt"]
    D -- ja --> F["Platz „angefragt“ und reserviert"]
    F --> G["Planer oder Stationsverantwortung<br>bestätigt oder lehnt ab"]
    G -- bestätigt --> E
    G -- abgelehnt --> H["Platz wieder frei, Kamerad bekommt Mail"]
    E --> I["Bis 24 Stunden vor der ersten Schicht:<br>einmal eine Mail mit den eigenen Schichten;<br>bestätigte Schichten zählen im Stundennachweis"]
```

Alternativ verteilt der Planer mit „Plan füllen“ automatisch und ändert einzelne Plätze von Hand.

### Mängelmanagement: vom Melden bis erledigt

**Wer:** jeder mit Modulzugang meldet; Verantwortliche (mit Standorten Wehrführung und Gerätewart des Standorts) und Zuständige bearbeiten. **Wo:** `/maengelmanagement`, per QR-Code am Gerät.

```mermaid
stateDiagram-v2
    [*] --> Offen: Kamerad meldet (Fotos, Dringlichkeit)
    Offen --> InBearbeitung: Verantwortlicher übernimmt oder weist zu
    InBearbeitung --> InBearbeitung: Rückfrage an den Melder, Antwort schließt sie
    InBearbeitung --> Erledigt: behoben, mit Notiz
    Offen --> Abgelehnt: kein Mangel
    Erledigt --> Offen: Melder öffnet wieder (bis 30 Tage, mit Begründung)
    Erledigt --> [*]
    Abgelehnt --> [*]
    InBearbeitung: In Bearbeitung
```

Ein neuer Mangel geht an alle Verantwortlichen, ohne Verantwortliche an die Admins. Hat das Objekt eine zuständige Person, wird ihr der Mangel gleich zugewiesen. Betrifft er ein Gerät der Ausrüstung, entsteht dort ein Schaden, der mit dem Mangel erledigt wird.

### Fahrzeuge: Prüfung und Mangel

**Wer:** Fahrzeugwarte; mit Standorten Wehrführung und Gerätewart des Standorts. **Wo:** `/fahrzeuge`, Fahrzeugprofil → Fristen.

```mermaid
flowchart TD
    A["Frist am Fahrzeug: HU, UVV, Wartung …<br>steht als Termin im Kalender der Fahrzeugwarte"] --> B["Vor Fälligkeit: Sammelmail an Fahrzeugwarte<br>und Wehrführung des Standorts"]
    B --> C["Prüfung eintragen: Checkliste je Schritt"]
    C --> D{"Bestanden?"}
    D -- ja --> E["Nächste Frist aus dem Intervall;<br>ein durch Prüfung gesetzter Status wird aufgehoben"]
    D -- nein --> F["Die Prüfung setzt den Fahrzeugstatus,<br>die Frist bleibt offen"]
    F --> G["Jeder Schritt mit Mangel wird<br>im Mängelmanagement gemeldet"]
    F --> H["Eine eingetragene Ausfallzeit sperrt<br>das Fahrzeug in der Fahrzeugbuchung"]
    C --> I["Kosten auf Wunsch als Ausgabe<br>an Finanzen übergeben"]
```

### Fahrtenbuch: Fahrt und Korrektur

**Wer:** alle mit Modulzugang fahren und tragen ein; Fahrtenbuchwarte korrigieren. **Wo:** `/fahrtenbuch`.

```mermaid
flowchart TD
    A["Fahrer: „Fahrt beginnen“<br>Fahrzeug, Kilometerstand, Zweck"] --> B{"Darf er das Fahrzeug fahren?<br>Führerschein, Einweisung"}
    B -- "nein" --> C["Hinweis, Fahrt bleibt möglich"]
    B -- ja --> D["Fahrt läuft: je Fahrzeug nur eine offene"]
    C --> D
    D --> E["„Fahrt beenden“: Kilometerstand, Tanken"]
    E --> F["Kilometerstand im Fahrzeugprofil aktualisiert"]
    E --> G{"Fehler in der Fahrt?"}
    G -- ja --> H["Fahrtenbuchwart: Korrektur mit Grund<br>oder Stornierung"]
    H --> I["Fahrten werden nie gelöscht;<br>jede Fassung bleibt nachvollziehbar"]
```

### Kleiderkammer: Ausgabe und Wäsche

**Wer:** Kleiderwarte; mit eingeschalteter Standortprüfung auch die Wehrführung für ihren Standort. **Wo:** `/admin/kleiderkammer`, für Kameraden `/kleiderkammer`.

```mermaid
flowchart TD
    A["Kleiderwart öffnet das Profil des Kameraden"] --> B{"Einzelteil mit Code<br>oder Mengenartikel?"}
    B -- Einzelteil --> C["„Teil ausgeben“: Code scannen oder suchen"]
    B -- Mengenartikel --> D["„Aus Bestand ausgeben“:<br>Bestand sinkt"]
    C --> E["Teil steht bei „Meine Ausrüstung“ des Kameraden"]
    D --> E
    E --> F["Kamerad: „Mängel melden“"]
    E --> G["Kamerad: „Wäsche anfordern“ mit Begründung"]
    F --> H["Kleiderwarte bekommen die Meldung"]
    G --> H
    H --> I["Kleiderwart erledigt: „gewaschen“<br>Wäsche im Protokoll, Kamerad wird informiert"]
    E --> J["„Zurücknehmen“: Teil wieder verfügbar"]
```

Ausgegeben wird nur an aktive Kameraden.

### Schlüssel: Ausgabe mit Quittung

**Wer:** Schlüsselwarte; mit Standorten jede Wehrführung. **Wo:** `/schluessel`.

```mermaid
flowchart TD
    A["Schlüsselwart: „Ausgeben“ an einen aktiven Kameraden,<br>optional Rückgabefrist"] --> B["Kamerad unterschreibt auf dem Bildschirm:<br>Schlüsselquittung"]
    B --> C["Schlüssel steht bei „Meine Schlüssel“"]
    C --> D["Rückgabe: Schlüsselwart nimmt zurück"]
    C --> E["Frist überschritten: einmal eine Mail<br>an den Kameraden"]
    C --> F["Kamerad oder Schlüsselwart: „Verloren“"]
    F --> G["Schlüsselwarte bekommen Mail mit dem,<br>was der Schlüssel schließt"]
    G --> H{"Wieder da?"}
    H -- ja --> I["„Wiedergefunden“: wieder verfügbar"]
    H -- nein --> J["„Aussondern“"]
```

Ein Schlüssel kann nur einmal gleichzeitig ausgegeben sein.

### Finanzen: Ausgabe einreichen und genehmigen

**Wer:** jeder mit Modulzugang reicht ein; Kassenwart und Wehrführung des Buchungsstandorts genehmigen; der Kassenwart bezahlt. **Wo:** `/finanzen`.

```mermaid
stateDiagram-v2
    [*] --> Eingereicht: Kamerad reicht ein (Betrag, Kostenstelle, Beleg)
    Eingereicht --> Genehmigt: Kassenwart oder Wehrführung genehmigt
    Eingereicht --> Abgelehnt: Ablehnen mit Kommentar
    Genehmigt --> Bezahlt: Kassenwart trägt die Zahlung ein
    Bezahlt --> [*]
    Abgelehnt --> [*]
```

- Die eigene Ausgabe genehmigt immer jemand anderes (außer Admins).
- Von zwei gleichzeitigen Entscheidungen gilt die erste.
- Einnahmen und bereits bezahlte Ausgaben bucht der Kassenwart direkt, ohne Genehmigung.
- Ein abgeschlossenes Jahr ist gesperrt.

### Verbandbuch: Eintrag und Nachtrag

**Wer:** jeder mit Modulzugang schreibt; die Wehrführung des Standorts, GBI/SBI und Admins verwalten. **Wo:** `/verbandbuch`.

```mermaid
flowchart TD
    A["Kamerad erfasst: Art, Zeitpunkt, Ort,<br>verletzte Person, Hergang, Erste Hilfe"] --> B["Unterschrift auf dem Bildschirm;<br>verletzte Person optional"]
    B --> C["Eintrag gesperrt, auch in der Datenbank;<br>Nummer je Jahr, Prüfsumme"]
    C --> D["Wehrführung des Standorts, GBI/SBI, Admins<br>und verletzte Person: Mail ohne Gesundheitsdaten"]
    C --> E{"Etwas ergänzen<br>oder korrigieren?"}
    E -- ja --> F["Nachtrag: ebenfalls unveränderlich"]
    C --> G["Aufbewahrung 10 Jahre;<br>vorher ist Löschen gesperrt"]
```

Das Verbandbuch führt ein eigenes Zugriffsprotokoll.

### Personalverwaltung: Änderungswunsch

**Wer:** jeder Kamerad meldet; wer seine Personalakte verwaltet, beantwortet. **Wo:** Personalakte → Laufbahn & Ehrungen, `/personalverwaltung/aenderungswuensche`.

```mermaid
flowchart TD
    A["Kamerad: „Änderung melden“<br>Thema und Beschreibung, höchstens fünf offene"] --> B["Zuständige bekommen Mail und Glocke"]
    B --> C{"Stimmt die Angabe?"}
    C -- ja --> D["Akte ändern: Dienstgrad, Abteilung,<br>Ehrung, Lehrgang …"]
    D --> E["„Erledigt“"]
    C -- nein --> F["„Nicht übernommen“ mit Begründung"]
    E --> G["Kamerad bekommt die Antwort und sieht<br>den Status in seiner Personalakte"]
    F --> G
```

Anschrift, Geburtsdatum und Mobilnummer trägt jeder Kamerad selbst ein, solange die Felder eingeschaltet sind; die Zuständigen bekommen dann nur den Hinweis, welche Felder sich geändert haben.

### Vereinsverwaltung: Beitritt und Vorstand

**Wer:** Antrag öffentlich ohne Anmeldung; der Vorstand des gewählten Vereins bearbeitet. **Wo:** `/vereinsbeitritt`, `/verein`.

```mermaid
flowchart TD
    A["Antrag auf /vereinsbeitritt:<br>Verein, Angaben, Einwilligungen, Bankverbindung"] --> B["Antrag beim Vorstand dieses Vereins"]
    B --> C{"Vorstand entscheidet"}
    C -- annehmen --> D["Passives Mitglied mit Standardbeitrag"]
    C -- ablehnen --> E["Antrag abgelehnt"]
    D --> F["SEPA-Mandat getrennt dokumentieren:<br>Unterschrift, Referenz, Status"]
    D --> G["Jugend- und Kinderfeuerwehr: beitragsfrei,<br>keine Bankdaten"]
```

Den Vorstand zusammenstellen:

```mermaid
flowchart TD
    A["Admin → Vereine: Verein anlegen,<br>Standort zuordnen"] --> B["Admin weist den Vorsitz zu<br>nur Kamerad des Vereins-Standorts"]
    B --> C["Vorsitz oder Stellvertretung:<br>Verein → Reiter „Vorstand“"]
    C --> D["Kamerad hinzufügen, Amt wählen:<br>Kassierer, Schriftführer, Beisitzer,<br>jeweils auch Stellvertretung"]
    D --> E["Zuordnung gibt den Zugang zum Verein"]
    C --> F["Das eigene Amt ändert der Vorsitz nicht;<br>das macht ein Admin"]
```
