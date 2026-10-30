<x-filament-panels::page>
    <p style="font-size: 0.875rem;">Erwartete Einsatzzeit = (niedrigster Startdruck − Reserve) × Flaschenvolumen ÷ Luftverbrauch. Rückzugsdruck nach Erreichen des Ziels: doppelter Verbrauch des Hinwegs, mindestens die Reserve.</p>
    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top: 1rem;">
            <x-filament::button type="submit">Speichern</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
