<x-filament-panels::page>
    <x-filament::section>
        <p style="font-weight:600">Zurzeit: {{ $on ? 'eingeschaltet' : 'ausgeschaltet' }}</p>
        <p style="margin-top:.5rem;color:#4b5563">Für den schnellen Blick direkt am Fahrzeug oder Gerät: Wer den QR-Code oder Barcode eines Geräts mit der Handykamera scannt, ohne in openwehr angemeldet zu sein, sieht</p>
        <ul style="margin:.5rem 0 0 1.25rem;list-style:disc;color:#4b5563">
            <li>Name, Code und Kategorie,</li>
            <li>ob es einsatzbereit ist (oder defekt, gesperrt, in Reparatur …) und Hinweise wie „Prüfung überfällig“,</li>
            <li>die nächste Prüfung,</li>
            <li>wohin es gehört (Standort, Fahrzeug, Geräteraum).</li>
        </ul>
        <p style="margin-top:.5rem;color:#4b5563">Nicht gezeigt werden Seriennummer, Kaufdaten, Ausleihen, Schäden im Detail und Namen. Angemeldete Kameraden kommen wie bisher zur Geräteseite, Gerätewarte in die Verwaltung. Ausgeschaltet führt der Scan ohne Login zur Anmeldung.</p>
    </x-filament::section>
</x-filament-panels::page>
