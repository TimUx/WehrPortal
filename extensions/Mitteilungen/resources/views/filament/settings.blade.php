<x-filament-panels::page>
    <div class="space-y-6">
        <p class="text-sm text-gray-600">Externe Ziele sind zusätzlich zur App. Zugangstoken werden verschlüsselt gespeichert und nie wieder angezeigt. Standort-Admins verwalten nur ihre Standorte; globale Ziele bleiben globalen Admins vorbehalten.</p>

        @foreach($scopeLabels as $scope => $label)
            <section class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-lg font-semibold">{{ $label }}</h2>
                <div class="mt-4 grid gap-5 xl:grid-cols-2">
                    @foreach(['ntfy' => 'ntfy', 'matrix' => 'Matrix'] as $transport => $name)
                        <div class="space-y-3 rounded-lg border border-gray-100 p-4">
                            <h3 class="font-semibold">{{ $name }}</h3>
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="data.{{ $scope }}.{{ $transport }}.enabled">
                                {{ $name }}-Versand aktivieren
                            </label>
                            <label class="block text-sm">HTTPS-Server
                                <input type="url" wire:model="data.{{ $scope }}.{{ $transport }}.server_url" placeholder="https://notify.example.com" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            </label>
                            <label class="block text-sm">{{ $transport === 'ntfy' ? 'Topic' : 'Matrix-Raum-ID' }}
                                <input type="text" wire:model="data.{{ $scope }}.{{ $transport }}.target" placeholder="{{ $transport === 'ntfy' ? 'wehr-allgemein' : '!raumid:example.com' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            </label>
                            <label class="block text-sm">Zugriffstoken
                                <input type="password" autocomplete="new-password" wire:model="data.{{ $scope }}.{{ $transport }}.token" placeholder="{{ $data[$scope][$transport]['has_token'] ? '•••••••• gespeichert – leer lassen, um es zu behalten' : '' }}" class="mt-1 w-full rounded-lg border-gray-300 text-sm">
                            </label>
                            @if($data[$scope][$transport]['has_token'])
                                <label class="flex items-center gap-2 text-sm text-red-700">
                                    <input type="checkbox" wire:model="data.{{ $scope }}.{{ $transport }}.remove_token">
                                    Gespeichertes Token entfernen
                                </label>
                            @endif
                            <p class="text-xs text-gray-500">Nur HTTPS auf öffentlich erreichbaren Servern. ntfy-Topics müssen zugriffsbeschränkt sein; Matrix-Räume privat/einladungsbasiert. Raum-Mitgliedschaften werden nicht automatisch mit Standorten synchronisiert.</p>
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex justify-end">
            <x-filament::button wire:click="save">Einstellungen speichern</x-filament::button>
        </div>
    </div>
</x-filament-panels::page>
