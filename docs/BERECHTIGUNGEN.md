# Berechtigungsmatrix

[Zur Hauptdokumentation](../README.md) · [Abläufe](ABLAEUFE.md) · [Standorte](LOCATIONS.md) · [Module](MODULE.md)

Wer in WehrPortal was darf, auf einen Blick. Die Einzelheiten je Modul stehen in [MODULE.md](MODULE.md), die Regeln für mehrere Standorte in [LOCATIONS.md](LOCATIONS.md). Die beiden Tabellen der Standardrollen werden von einem Test gegen den Code geprüft (`tests/Feature/Docs/PermissionMatrixTest.php`): Ändert sich ein Profil in `StandardRoleCatalog`, schlägt der Test an, bis diese Seite nachgezogen ist.

## Inhalt

- [Woher ein Recht kommt](#woher-ein-recht-kommt)
- [Standardrollen: Führung von Standort und Kommune](#standardrollen-führung-von-standort-und-kommune)
- [Standardrollen: Jugend- und Kinderfeuerwehr](#standardrollen-jugend--und-kinderfeuerwehr)
- [Klassische Modulrollen](#klassische-modulrollen)
- [Admin-Bereich](#admin-bereich)
- [Sonderfälle](#sonderfälle)

## Woher ein Recht kommt

Ein Recht kommt immer aus genau einer dieser Quellen. Sie ergänzen sich; keine nimmt etwas weg.

| Quelle | Vergibt | Wo | Gilt für |
|---|---|---|---|
| **Admin** (`is_admin`) | globaler Admin | Admin → Kameraden, Häkchen „Administrator“; `ADMIN_EMAILS` beim Start | alles, alle Standorte, jedes aktive Modul |
| **Modulzugang** | globaler Admin | Admin → Module → „x Kameraden“ oder „Neue Kameraden automatisch freischalten“ | das Modul benutzen: eigene Daten, melden, eintragen |
| **Klassische Modulrolle** | globaler Admin | Admin → Module → „x Kameraden“ oder im Kameradenformular | das Modul verwalten, immer für die **ganze Kommune** |
| **Standardrolle** | globaler Admin | Admin → Standardrollen | ein festes Paket von Modulrollen für **einen Standort**, **eine Abteilung** oder die **ganze Kommune** |
| **Standort-Admin** | globaler Admin oder Standort-Admin des Standorts | Admin → Standorte → Mitglieder | Name, Mitglieder und Zuordnung von Fahrzeugen und Ausrüstung des eigenen Standorts |
| **Standort-Modulrolle** (optional) | globaler Admin | Admin → Standorte → Standort bearbeiten → „Standort-Modulrollen und Verlauf“ | Anwesenheit, Mitteilungen und die Kleiderkammer-Teilrechte an einem Standort; nur mit den zugehörigen Schaltern, siehe [LOCATIONS.md](LOCATIONS.md) |
| **Amt im Vereinsvorstand** | globaler Admin, danach der Vorsitz des Vereins | Admin → Vereine oder Verein → Reiter „Vorstand“ | den eigenen Verein |

Für alle gilt:

- Nur **aktive** Kameraden haben Rechte. Wer inaktiv oder ausgetreten ist, verliert sie sofort, ohne dass eine Rolle entzogen werden muss.
- Ein **ausgeschaltetes Modul** gibt niemandem etwas, auch nicht über eine Standardrolle.
- Eine Standardrolle für einen Standort wirkt nur, solange der Kamerad diesem Standort angehört.
- Jede Standardrolle kann als **Stellvertretung** vergeben werden. Sie hat dieselben Rechte und bekommt dieselben Benachrichtigungen; in Listen steht „Stv.“ davor.
- Gibt es für eine Aufgabe niemanden mit der passenden Rolle, gehen die Benachrichtigungen an die Admins.

## Standardrollen: Führung von Standort und Kommune

Die Zellen nennen die Modulrolle, die das Profil in diesem Modul mitbringt (Schlüssel wie im Code). „–“ heißt: Das Profil gibt in diesem Modul nichts; der Kamerad hat dort nur, was ihm Modulzugang oder eine klassische Rolle gibt.

Zwei Profile stehen nicht in der Tabelle, weil sie keine einzelnen Modulrollen mitbringen:

- **GBI / SBI** (`gemeindebrandinspektor`, ganze Kommune): verwaltet **jedes** Modul an allen Standorten, auch Verbandbuch, Expositionen, Finanzen und Personalakten. Nicht enthalten ist der Admin-Kernbereich (Kameraden, Module, Standardrollen, Logs). Fach-Benachrichtigungen der Module bekommt das Profil nicht.
- **Bürgermeister** (`buergermeister`, ganze Kommune): sieht nur die jährlichen Einsatzzahlen aller Standorte im Admin-Bereich; keine Einzelberichte, kein Moduleinstieg. Vergebbar nur mit `LOCATIONS_LEADERSHIP_ENABLED=true` und aktivem Einsatzbericht.

| Modul | Wehrführung `wehrfuehrung` (ein Standort) | Reichweite der Wehrführung | Gerätewart `geraetewart` (ein Standort) | Atemschutzgerätewart `atemschutzwart` (Kommune) | Brandschutzamt `brandschutzamt` (Kommune) |
|---|---|---|---|---|---|
| Anwesenheit `anwesenheit` | `verwalter` | Listen des Standorts | – | – | – |
| Atemschutz `atemschutz` | `atemschutzwart` | Geräteträger des Standorts pflegen | – | `atemschutzwart` | – |
| Ausrüstung `ausruestung` | `geraetewart` | Geräte des Standorts | `geraetewart` | – | `geraetewart` |
| Dateien `dateien` | – | – | – | – | – |
| Einsatzbericht `einsatzbericht` | `wehrführung` | Berichte des Standorts lesen, korrigieren, freigeben; Jahresstatistik und Kameraden des Standorts; kein Jahresbericht aller Module | – | – | `einsicht` |
| Expositionen `expositionen` | `wehrführung` | Expositionen des Standorts | – | – | – |
| Fahrtenbuch `fahrtenbuch` | `fahrtenbuchwart` | Fahrzeuge des Standorts | – | – | – |
| Fahrzeugbuchung `fahrzeugbuchung` | `entscheider` | Konflikte bei Fahrzeugen des Standorts entscheiden, dort Buchungen vorzeitig beenden | – | – | – |
| Fahrzeuge `fahrzeuge` | `fahrzeugwart` | Fahrzeuge des Standorts | `fahrzeugwart` | – | – |
| Finanzen `finanzen` | `wehrführung` | Ausgaben des Standorts genehmigen, dessen Buchungen und Budget | – | – | – |
| Führerscheine `fuehrerscheine` | `fuehrerscheinpruefer` | Führerscheine der Kameraden des Standorts prüfen; nicht das Prüfintervall | – | – | – |
| Gefahrenmatrix `gefahrenmatrix` | – | – | – | – | – |
| Gefahrstoffe `gefahrstoffe` | `gefahrstoffbeauftragter` | ganze Kommune (Daten ohne Standort) | – | – | – |
| Hydrantenkontrolle `hydrantenkontrolle` | `hydrantenwart` | Hydranten und Kontrollen des Standorts | – | – | – |
| Kalender `kalender` | `terminverwalter` | Termine, Terminabfragen, Orte und Kleidung des Standorts | – | – | – |
| Kleiderkammer `kleiderkammer` | `bestand` `kleiderwart` | Personen, Ausgaben und Mengenbestand des Standorts – **nur** mit `LOCATIONS_CLOTHING_PERSONAL_ENABLED` bzw. `LOCATIONS_CLOTHING_STOCK_ENABLED`; kein Katalog | – | – | `bestand` `kleiderwart` |
| Lehrgänge `lehrgaenge` | `wehrführung` | Lehrgänge der Kameraden des Standorts: Anmeldungen, anmelden, absolvierte eintragen; nicht Lehrgangsliste, fox112-Abgleich, Ergebnis-Import | – | – | – |
| Mängelmanagement `maengelmanagement` | `verantwortlicher` | Mängel des Standorts | `verantwortlicher` | – | – |
| Mitteilungen `mitteilungen` | – | als Standort-Admin: Mitteilungen des eigenen Standorts | – | – | – |
| Objektpläne `objektplaene` | `planverwalter` | ganze Kommune (Daten ohne Standort) | – | – | – |
| Personalverwaltung `personalverwaltung` | `personalverwalter` | Personalakten der Kameraden des Standorts | – | – | – |
| Schichtplan `schichtplan` | `planer` | Pläne des Standorts | – | – | – |
| Schlüssel `schluessel` | `schluesselwart` | ganze Kommune (Daten ohne Standort) | – | – | – |
| Übungsdienste `uebungsdienste` | `wehrführung` | Dienste der Einsatzabteilung des Standorts | – | – | – |
| Unterweisungen `unterweisungen` | `unterweisungsbeauftragter` | für Kameraden des Standorts durchführen, eigene Termine verwalten; nicht die Arten | – | `unterweisungsbeauftragter` | – |
| Verbandbuch `verbandbuch` | `wehrführung` | Einträge des Standorts | – | – | – |
| Vereinsverwaltung `verein` | – | – | – | – | – |
| Wartung `wartung` | `geraetewart` | Wartungen des Standorts planen, durchführen, stornieren; nicht Vorlagen, Fachfirmen, Qualifikationen, Stundensatz | `geraetewart` | – | – |
| Wasserkarte `wasserkarte` | `hydrantenwart` | ganze Kommune (Daten ohne Standort) | – | – | – |

Anmerkungen:

- Die **Wehrführung** ist an ihrem Standort zugleich Standort-Admin.
- Der **Atemschutzgerätewart** verwaltet bei den Unterweisungen nur Arten, die als Atemschutz-Unterweisung zählen.
- Das **Brandschutzamt** liest die Einsatzberichte aller Standorte, ändert keinen und sieht weder Statistik noch Einstellungen. Das Profil ist nur vergebbar, wenn Ausrüstung und Kleiderkammer aktiv und beide Kleiderkammer-Standortschalter gesetzt sind.
- Der **Gerätewart** bekommt die Fahrzeugfristen und Geräte-Meldungen seines Standorts.

## Standardrollen: Jugend- und Kinderfeuerwehr

Diese Profile gelten für eine Abteilung „Jugendfeuerwehr“ bzw. „Kinderfeuerwehr“: Jugend- und Kinderwart für die Abteilung eines Standorts, Gemeinde-/Stadtjugendwart und Gemeinde-/Stadtkinderwart für alle Abteilungen dieser Art in der Kommune. Sie erkennen ihre Abteilung am Namen; diese Abteilungen lassen sich deshalb nicht umbenennen. In allen anderen Modulen bringen die Profile nichts mit.

| Modul | Jugendwart `jugendwart` | Kinderwart `kinderwart` | Gemeinde-/Stadtjugendwart `gemeindejugendwart` | Gemeinde-/Stadtkinderwart `gemeindekinderwart` | Reichweite |
|---|---|---|---|---|---|
| Anwesenheit `anwesenheit` | `jugendwart` | `kinderwart` | `gemeindejugendwart` | `gemeindekinderwart` | Listen der eigenen Abteilung |
| Kalender `kalender` | `terminverwalter` | `terminverwalter` | `terminverwalter` | `terminverwalter` | Termine, deren Zielgruppe die eigene Abteilung ist (dazu einzelne Kameraden des Standorts): anlegen, ändern, absagen, Rückmeldungen und Anwesenheit. Keine andere Abteilung, keine Funktion; keine Terminabfragen, externen Kalender, Orte und Kleidung |
| Personalverwaltung `personalverwaltung` | `personalverwalter` | `personalverwalter` | `personalverwalter` | `personalverwalter` | Personalakten der eigenen Abteilung |
| Übungsdienste `uebungsdienste` | `jugendwart` | `kinderwart` | `gemeindejugendwart` | `gemeindekinderwart` | Dienste der eigenen Abteilung |

## Klassische Modulrollen

Diese Rollen vergibt ein Admin unter Admin → Module. Sie gelten für die ganze Kommune. „Alle mit Modulzugang“ nennt, was ein Kamerad ohne Rolle im Modul tut. Admins dürfen in jedem aktiven Modul alles.

| Modul | Rolle | Die Rolle darf | Alle mit Modulzugang |
|---|---|---|---|
| Anwesenheit | Verwalter `verwalter` | alle Listen bearbeiten, auch abgegebene; Vorlagen | Liste anlegen, eigenen Entwurf bearbeiten und abgeben, PDF und E-Mail |
| Atemschutz | Atemschutzgerätewart `atemschutzwart`; Überwacher `ueberwacher` (nur Merkmal) | Geräteträger, G26.3, Nachweise, Einstellungen, Atemschutzausrüstung | Truppüberwachung im Einsatz führen, eigenen Atemschutzpass sehen |
| Ausrüstung | Gerätewart `geraetewart` | Geräte, Prüffristen, Ausleihe, Rückrufe, Beladeplan, Inventur | Geräte ansehen, ausleihen oder reservieren, Schäden melden |
| Dateien | Gruppenverwalter `gruppenverwalter` | Dateigruppen verwalten | nur Ablagen und Einträge, für die es eigene Rechte gibt |
| Einsatzbericht | Wehrführung `wehrführung`; Einsicht `einsicht` | Wehrführung: lesen, korrigieren, freigeben, auswerten, Pflichtfelder. Einsicht: alle Berichte lesen und drucken, nichts ändern | Bericht anlegen, Bericht aus der Alarmierung übernehmen, den eigenen bis zum Abschluss führen; Lagemeldungen bei laufendem Einsatz; eigene Einsätze im Profil |
| Expositionen | Expositionsbeauftragter `expositionsbeauftragter` | alle Expositionen verwalten, Meldeliste für die ZED | eigene Expositionen erfassen und sehen |
| Fahrtenbuch | Fahrtenbuchwart `fahrtenbuchwart` | Korrekturen, Stornierungen, Auswertung, Export | Fahrten beginnen und beenden, nachtragen, tanken, Fahrtenbücher lesen |
| Fahrzeugbuchung | Entscheider `entscheider` | Buchungskonflikte entscheiden, Entscheidungen ändern und zurücknehmen, laufende Buchungen anderer beenden | Kalender sehen, eigene Buchungen anlegen, ändern, stornieren, Konflikte zur Entscheidung vorlegen |
| Fahrzeuge | Fahrzeugwart `fahrzeugwart` | Fahrzeuge anlegen und bearbeiten, Status, Werkstattzeiten, Fristen, Prüfungen, Fahrzeugakte | Übersicht und Fahrzeugprofile sehen |
| Finanzen | Kassenwart `kassenwart`; Wehrführung `wehrführung` | Kassenwart: Kostenstellen, Budgets, direkt buchen, bezahlen, Jahresabschluss. Wehrführung: genehmigen, Buchungen und Budget | eigene Ausgaben mit Beleg einreichen und deren Stand sehen |
| Führerscheine | Führerscheinprüfer `fuehrerscheinpruefer` | eingereichte Führerscheine bestätigen oder zurückweisen, Prüfintervall | eigenen Führerschein mit Fotos einreichen, Fristen sehen |
| Gefahrenmatrix | – | – | Gefahrenmatrix nutzen |
| Gefahrstoffe | Gefahrstoffbeauftragter `gefahrstoffbeauftragter` | Datenbestände importieren und pflegen | Stoffe suchen |
| Hydrantenkontrolle | Hydrantenwart `hydrantenwart`; übergreifend `uebergreifend` | Hydranten und Jahreskontrollen, am eigenen Ort bzw. übergreifend | – |
| Kalender | Terminverwalter `terminverwalter` | Termine, Terminabfragen, externe Kalender, Orte und Kleidung, Anwesenheit eintragen | Termine der eigenen Zielgruppe sehen, zu- und absagen, Kalender abonnieren |
| Kleiderkammer | Kleiderwart `kleiderwart` | ausgeben, zurücknehmen, Größen, Bestand, Katalog, Etiketten, Mängel und Wäsche bearbeiten | eigene Ausrüstung sehen, Mängel melden, Wäsche anfordern |
| Lehrgänge | Ausbildungsleiter `ausbildungsleiter`; Wehrführung `wehrführung` | Ausbildungsleiter: Lehrgangsliste, fox112, Anmeldungen, Termine, absolvierte eintragen, Import. Wehrführung: Lehrgänge der Kameraden pflegen, ohne Lehrgangsliste und Import | Interesse melden, eigene Lehrgänge und Termine sehen, absagen |
| Mängelmanagement | Verantwortlicher `verantwortlicher` | Status, Dringlichkeit, Zuständigen ändern, Rückfragen, Geräte und Objekte pflegen | Mängel melden, alle Mängel sehen, kommentieren, eigene wieder öffnen |
| Mitteilungen | Redakteur `redakteur` | Mitteilungen schreiben, Zielgruppe, Lesebestätigungen, erinnern | Mitteilungen lesen und bestätigen |
| Objektpläne | Planverwalter `planverwalter` | Objekte und Unterlagen pflegen | Pläne lesen, offline bereitstellen |
| Personalverwaltung | Personalverwalter `personalverwalter`; Personaleinsicht `personaleinsicht` | Verwalter: Akten, neue Mitglieder, Sammelverarbeitung, Ehrungen, Änderungswünsche, Einstellungen. Einsicht: Liste, Akten und Auswertungen lesen | eigene Personalakte, Änderung melden, Dienstausweis |
| Schichtplan | Planer `planer` | Pläne, Stationen, Verteilung, veröffentlichen, Eintragungen bestätigen | veröffentlichte Pläne sehen, sich selbst ein- und austragen |
| Schlüssel | Schlüsselwart `schluesselwart` | Schlüssel pflegen, ausgeben mit Quittung, zurücknehmen, Verlust | eigene Schlüssel sehen, Verlust melden |
| Übungsdienste | Wehrführung `wehrführung`; Jugendwart `jugendwart`; Kinderwart `kinderwart`; Gemeindejugendwart `gemeindejugendwart`; Gemeindekinderwart `gemeindekinderwart` | Dienste der jeweiligen Abteilung planen | nächsten Dienst sehen (ohne Thema), Rückmeldung über den Kalender |
| Unterweisungen | Unterweisungsbeauftragter `unterweisungsbeauftragter` | Arten, Durchführen, Übersicht | eigene Unterweisungen sehen, auf dem Tablet unterschreiben |
| Verbandbuch | Wehrführung `wehrführung` | alle Einträge verwalten | Eintrag schreiben und unterschreiben; sehen nur Einträge, in denen sie verletzte Person, Ersthelfer oder Erfasser sind |
| Vereinsverwaltung | Vereinsvorstand `vereinsvorstand` | den zugeordneten Verein verwalten (siehe [Sonderfälle](#sonderfälle)) | – |
| Wartung | Gerätewart `geraetewart`; Prüfer `pruefer` | Gerätewart: Vorlagen, Fachfirmen, Prüfer und Qualifikationen, planen, stornieren, Stundensatz. Prüfer: Wartungen durchführen und unterschreiben | fällige Wartungen, Geräte und Protokolle sehen |
| Wasserkarte | Hydrantenwart `hydrantenwart` | Quellen anlegen und bearbeiten, Status, Prüfungen, Mängel beheben, Import | Karte und Daten sehen, Mängel melden |

Mit eingeschalteten Standorten beachten vier klassische Rollen ihr Modul nicht mehr; die Rollenauswahl zeigt sie mit „(ohne Wirkung)“: **Personalverwalter** und **Personaleinsicht** (Personalverwaltung), **Wehrführung** (Verbandbuch) und **Expositionsbeauftragter**. Diese Aufgaben liegen dann bei GBI/SBI und der Wehrführung des Standorts.

## Admin-Bereich

| Bereich | Wer |
|---|---|
| Kameraden, Abteilungen, Funktionen, Ansprechpartner | globaler Admin |
| Module ein- und ausschalten, Modulzugang und klassische Rollen | globaler Admin |
| Standardrollen vergeben und widerrufen, „Gemeinde oder Stadt“ | globaler Admin |
| Standorte anlegen, Standort-Modulrollen | globaler Admin |
| Eigener Standort: Name, Mitglieder, Zuordnung von Fahrzeugen und Ausrüstung | Standort-Admin (auch jede Wehrführung an ihrem Standort) |
| Vereine anlegen, Vorstand zuweisen | globaler Admin |
| Logs, E-Mail-Texte, Einstellungen, Feedback, Single Sign-On | globaler Admin |
| „Als Kamerad ansehen“, Einladung, Login-Link, „Löschen mit Übertragung“ | globaler Admin |
| Verwaltungsbereich eines Moduls (`/admin/<modul>`) | wer das Modul verwaltet (klassische Rolle oder passende Standardrolle) und Admins |

Wer nur ein Modul verwaltet, kommt über „Admin“ direkt in seinen Bereich und sieht den Kernbereich nicht.

## Sonderfälle

- **Vereinsvorstand:** Der Zugang hängt an der Zuordnung zu genau einem Verein, nicht an einer Rolle für die Kommune. Die Ämter sind Vorsitzender, Kassierer, Schriftführer und Beisitzer, jeweils auch als Stellvertretung. Alle Vorstandsmitglieder verwalten Anträge, Mitglieder und Beiträge ihres Vereins. Den Vorstand stellt der **Vorsitz** (mit Stellvertretung) selbst zusammen; das eigene Amt ändert er nicht, und den Vorsitz bekommen nur Kameraden des Standorts des Vereins. Admins dürfen alles.
- **Einsatzbericht ohne Rolle:** Jeder Kamerad mit Modulzugang legt einen Bericht an und führt ihn, bis er abgeschlossen ist. Danach öffnen ihn nur noch die Wehrführung des Standorts, GBI/SBI, Admins und die Rolle Einsicht.
- **Entscheider ohne Besetzung:** Gibt es keinen aktiven Entscheider für eine Buchung, entscheiden die Admins.
- **Mitglieder ohne Login** (ohne E-Mail-Adresse, z. B. Kinder der Kinderfeuerwehr): sind in allen Modulen wählbar, können sich aber nicht anmelden und keine Rolle ausüben.
- **Erziehungsberechtigte:** haben kein Konto. Sie bekommen Kopien der Kalender-Mails ihres Kindes und antworten über einen befristeten Link.
- **Wachen-Monitor** der Fahrzeugbuchung: für Admins oder mit `DISPLAY_TOKEN`; alle anderen bekommen 404.
- **Öffentlich ohne Anmeldung:** Vereinsbeitritt (`/vereinsbeitritt`), öffentlicher Kalender, Prüfung digitaler Nachweise per QR-Code (10 Minuten gültig), QR-Zugang externer Wehren zu einem laufenden Einsatz und – wenn eingeschaltet – die Geräteinfo der Ausrüstung.
