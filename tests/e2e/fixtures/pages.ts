/**
 * Pages that are opened, checked and photographed. `ready` is text that only shows once the page has
 * rendered its content (hidden copies, e.g. in menus, don't count) (seeded by Database\Seeders\ScreenshotSeeder); the screenshot is written to
 * screenshots/<module>/<name>.png.
 */
export type ScreenshotPage = {
    module: string;
    name: string;
    path: string;
    ready: string;
    /** CSS selector that must be visible as well (e.g. a FullCalendar view) */
    selector?: string;
};

export const pages: ScreenshotPage[] = [
    { module: 'core', name: 'startseite', path: '/', ready: 'Fahrzeugbuchung' },
    { module: 'fahrzeugbuchung', name: 'kalender', path: '/fahrzeugbuchung', ready: 'Zeltlager Aufbau', selector: '.fc-event' },
    { module: 'fahrzeugbuchung', name: 'meine-buchungen', path: '/fahrzeugbuchung/meine-buchungen', ready: 'Fahrzeugpflege' },
    { module: 'kleiderkammer', name: 'uebersicht', path: '/kleiderkammer', ready: 'Kleiderkammer' },
    { module: 'anwesenheit', name: 'listen', path: '/anwesenheit', ready: 'Übungsdienst Technische Hilfe' },
    { module: 'hydrantenkontrolle', name: 'hydranten', path: '/hydrantenkontrolle/hydranten', ready: 'H-001' },
    { module: 'admin', name: 'dashboard', path: '/admin', ready: 'Dashboard' },
];
