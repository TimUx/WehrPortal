<x-layouts.app :title="$service ? 'Übungsdienst ändern' : 'Übungsdienst anlegen'">
    <livewire:uebungsdienste.service-form :service="$service" />
</x-layouts.app>
