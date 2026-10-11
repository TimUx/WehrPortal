<x-layouts.app :title="$entry ? 'Buchung ändern' : ($direct ? 'Buchung erfassen' : 'Ausgabe einreichen')">
    @include('finanzen::partials.nav')
    <livewire:finanzen.entry-form :entry="$entry" :direct="$direct" />
</x-layouts.app>
