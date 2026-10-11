<x-layouts.app :title="'Beleg '.$entry->code()">
    @include('finanzen::partials.nav')
    <livewire:finanzen.entry-detail :entry="$entry" />
</x-layouts.app>
