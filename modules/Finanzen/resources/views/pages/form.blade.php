<x-layouts.app :title="$entry ? 'Buchung ändern' : ($direct ? 'Buchung erfassen' : 'Ausgabe einreichen')">
    <livewire:finanzen.entry-form :entry="$entry" :direct="$direct" />
</x-layouts.app>
