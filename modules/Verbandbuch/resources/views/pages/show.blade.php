<x-layouts.app :title="'Verbandbuch '.$entry->code()">
    @include('verbandbuch::partials.nav')
    <livewire:verbandbuch.entry-detail :entry="$entry" />
</x-layouts.app>
