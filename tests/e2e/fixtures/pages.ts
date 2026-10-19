/**
 * Pages that are opened, checked and photographed: the core and one or two pages per module and fork
 * extension. `ready` is text that only shows once the page has rendered its content (seeded by
 * Database\Seeders\ScreenshotSeeder; hidden copies, e.g. in menus, don't count); the screenshot is
 * written to screenshots/<module>/<name>.png.
 */
export type ScreenshotPage = {
    module: string;
    name: string;
    path: string;
    ready: string;
    /** CSS selector that must be visible as well (e.g. a FullCalendar event) */
    selector?: string;
};

export const pages: ScreenshotPage[] = [
    // Core
    { module: 'core', name: 'startseite', path: '/', ready: 'Neue Einsatzjacken eingetroffen' },
    { module: 'core', name: 'personalakte', path: '/personalakte', ready: 'Anna Admin' },
    { module: 'core', name: 'bestand', path: '/bestand', ready: 'Ersatzbeschaffung' },
    { module: 'admin', name: 'dashboard', path: '/admin', ready: 'Letzte Anmeldungen' },

    // Upstream modules
    { module: 'fahrzeugbuchung', name: 'kalender', path: '/fahrzeugbuchung', ready: 'Zeltlager Aufbau', selector: '.fc-event' },
    { module: 'fahrzeugbuchung', name: 'meine-buchungen', path: '/fahrzeugbuchung/meine-buchungen', ready: 'Fahrzeugpflege' },
    { module: 'kleiderkammer', name: 'dashboard', path: '/admin/kleiderkammer', ready: 'Offene Leihen' },
    { module: 'mitteilungen', name: 'uebersicht', path: '/mitteilungen', ready: 'Grillfest der Jugendfeuerwehr' },
    { module: 'kalender', name: 'termine', path: '/kalender', ready: 'Tag der offenen Tür' },
    { module: 'maengelmanagement', name: 'maengel', path: '/maengelmanagement', ready: 'Rolltor schließt nicht vollständig' },
    { module: 'ausruestung', name: 'geraete', path: '/ausruestung/geraete', ready: 'Pressluftatmer PA 1' },
    { module: 'wartung', name: 'faellig', path: '/wartung', ready: 'Jahresprüfung Pressluftatmer' },
    { module: 'schichtplan', name: 'plaene', path: '/schichtplan', ready: 'Tag der offenen Tür' },
    { module: 'lehrgaenge', name: 'ausbildungsstand', path: '/lehrgaenge/ausbildungsstand', ready: 'Hanna Hydrant' },
    { module: 'uebungsdienste', name: 'planer', path: '/uebungsdienste', ready: 'Wasserförderung über lange Wegstrecken' },
    { module: 'einsatzbericht', name: 'einsaetze', path: '/einsatzbericht', ready: 'TH 1 – Ölspur' },
    { module: 'einsatzbericht', name: 'statistik', path: '/einsatzbericht/statistik', ready: 'Personalstunden' },
    { module: 'finanzen', name: 'budget', path: '/finanzen', ready: 'Einsatzabteilung' },
    { module: 'verbandbuch', name: 'eintraege', path: '/verbandbuch', ready: 'Bernd Blaulicht' },
    { module: 'expositionen', name: 'meldeliste', path: '/expositionen/verwaltung', ready: 'Bernd Blaulicht' },
    { module: 'fuehrerscheine', name: 'pruefen', path: '/fuehrerscheine/pruefen', ready: 'Hanna Hydrant' },
    { module: 'unterweisungen', name: 'uebersicht', path: '/unterweisungen/uebersicht', ready: 'Bernd Blaulicht' },
    { module: 'atemschutz', name: 'geraetetraeger', path: '/atemschutz/geraetetraeger', ready: 'Hanna Hydrant' },
    { module: 'fahrzeuge', name: 'fahrzeuge', path: '/fahrzeuge', ready: 'MU-FW 191' },
    { module: 'fahrzeuge', name: 'fristen', path: '/fahrzeuge/fristen', ready: 'Hauptuntersuchung (HU)' },
    { module: 'fahrtenbuch', name: 'fahrzeuge', path: '/fahrtenbuch', ready: '184.300 km' },
    { module: 'wasserkarte', name: 'karte', path: '/wasserkarte', ready: '2 verfügbar · 1 defekt', selector: '.leaflet-container' },
    { module: 'objektplaene', name: 'objekte', path: '/objektplaene', ready: 'Grundschule Musterstadt' },
    { module: 'personalverwaltung', name: 'kameraden', path: '/personalverwaltung', ready: 'Klara Lösch' },
    { module: 'schluessel', name: 'schluessel', path: '/schluessel', ready: 'Gerätehaus Haupteingang' },
    { module: 'gefahrenmatrix', name: 'matrix', path: '/gefahrenmatrix', ready: 'AAAA' },
    { module: 'gefahrstoffe', name: 'datenbank', path: '/gefahrstoffe', ready: 'Dieselkraftstoff' },

    // Fork extensions
    { module: 'anwesenheit', name: 'listen', path: '/anwesenheit', ready: 'Übungsdienst Technische Hilfe' },
    { module: 'hydrantenkontrolle', name: 'jahreskontrollen', path: '/hydrantenkontrolle', ready: 'Musterstadt-Mitte' },
    { module: 'hydrantenkontrolle', name: 'hydranten', path: '/hydrantenkontrolle/hydranten', ready: 'H-001' },
    { module: 'dateien', name: 'bereiche', path: '/dateien', ready: 'Wehrführung' },
    { module: 'locations', name: 'standorte', path: '/admin/standorte', ready: 'Musterstadt-Nord' },
];
