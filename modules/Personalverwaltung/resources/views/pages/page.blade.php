{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @include('personalverwaltung::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
