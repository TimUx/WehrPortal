<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit">
                Speichern
            </x-filament::button>

            @if(app(\App\Services\OrganizationSettings::class)->logoUrl())
                <x-filament::button
                    wire:click="deleteLogo"
                    color="danger"
                    outlined
                >
                    Logo entfernen
                </x-filament::button>
            @endif
        </div>
    </form>

    @if(app(\App\Services\OrganizationSettings::class)->logoUrl())
        <div class="mt-4">
            <p class="text-sm text-gray-500 mb-2">Aktuelles Logo:</p>
            <img src="{{ app(\App\Services\OrganizationSettings::class)->logoUrl() }}"
                 alt="Wehr-Logo"
                 class="h-16 object-contain" />
        </div>
    @endif

    {{-- Dienste: API keys for Google Maps and OpenRouter --}}
    <div style="margin-top: 2.5rem;">
        <h2 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem;">Dienste</h2>
        <form wire:submit="saveServices">
            {{ $this->servicesForm }}

            <div style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
                <x-filament::button type="submit">
                    Dienste speichern
                </x-filament::button>

                <x-filament::button type="button" color="gray" wire:click="testGoogleMaps" wire:loading.attr="disabled" wire:target="testGoogleMaps">
                    Google Maps testen
                </x-filament::button>

                <x-filament::button type="button" color="gray" wire:click="testOpenRouter" wire:loading.attr="disabled" wire:target="testOpenRouter">
                    OpenRouter testen
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
