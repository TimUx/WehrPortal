<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">QR / Barcode scannen</x-slot>
            <x-slot name="description">
                Halte einen Artikel-Code vor die Kamera. Du wirst direkt zum Artikel weitergeleitet.
            </x-slot>

            <div class="mx-auto max-w-md space-y-3" wire:ignore>
                <div id="kk-scanner" class="overflow-hidden rounded-xl bg-gray-950/5 dark:bg-white/5"></div>

                <p id="kk-scanner-error" class="hidden text-sm text-danger-600 dark:text-danger-400" role="alert"></p>

                <div class="flex gap-3">
                    <x-filament::button id="kk-scanner-start" icon="heroicon-o-camera">
                        Kamera starten
                    </x-filament::button>
                    <x-filament::button id="kk-scanner-stop" color="gray" class="hidden">
                        Stoppen
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Code manuell eingeben</x-slot>
            <form id="kk-manual-form" class="flex gap-3" wire:ignore>
                <x-filament::input.wrapper class="flex-1">
                    <x-filament::input
                        type="text"
                        id="kk-manual-code"
                        name="code"
                        placeholder="z. B. WEHREJA0001"
                        autocomplete="off"
                    />
                </x-filament::input.wrapper>
                <x-filament::button type="submit">
                    Öffnen
                </x-filament::button>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
