<x-layouts.app :title="$event ? 'Termin bearbeiten' : 'Neuer Termin'">
    <livewire:kalender.event-form :event="$event" />
</x-layouts.app>
