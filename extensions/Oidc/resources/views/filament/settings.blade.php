<x-filament-panels::page>
    @php($status = $this->status())

    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
        <x-filament::badge :color="$status['active'] ? 'success' : 'gray'">
            {{ $status['active'] ? 'Aktiv' : 'Nicht aktiv' }}
        </x-filament::badge>
        <x-filament::badge :color="$status['configured'] ? 'success' : 'warning'">
            {{ $status['configured'] ? 'Konfiguriert' : 'Unvollständig konfiguriert' }}
        </x-filament::badge>
        <x-filament::badge :color="$status['local_admins'] > 0 ? 'success' : 'danger'">
            {{ $status['local_admins'] }} lokale(r) System-Admin(s)
        </x-filament::badge>
    </div>

    @if($status['force_disabled'])
        <p style="color: #b45309; font-weight: 600;">
            OIDC_FORCE_DISABLED ist gesetzt: Der Single-Sign-On-Login ist ausgeschaltet, die Anmeldung per Link gilt für alle.
        </p>
    @endif

    <div>
        <p style="font-size: 0.875rem; margin-bottom: 0.25rem;">Redirect-URI (im Identity Provider als gültige Weiterleitungs-URI eintragen):</p>
        <code style="font-size: 0.875rem; user-select: all;">{{ $status['redirect_uri'] }}</code>
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 1.5rem; display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem;">
            <x-filament::button type="submit">Speichern</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                Verbindung testen
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
