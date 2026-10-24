<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Export --}}
        <x-filament::section>
            <x-slot name="heading">Export</x-slot>
            <x-slot name="description">Wähle aus, was in die JSON-Datei kommen soll.</x-slot>

            <form wire:submit="export">
                {{ $this->exportForm }}

                <div class="mt-4">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-down-tray" wire:loading.attr="disabled" wire:target="export">
                        Exportieren
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Import --}}
        <x-filament::section>
            <x-slot name="heading">Import</x-slot>
            <x-slot name="description">
                Daten aus einem WehrPortal-Export importieren.
                Bestehende Datensätze werden per Upsert aktualisiert – nichts wird gelöscht.
            </x-slot>

            <form wire:submit="import">
                {{ $this->importForm }}

                <div class="mt-4 flex items-center gap-3">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">
                        Jetzt importieren
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
