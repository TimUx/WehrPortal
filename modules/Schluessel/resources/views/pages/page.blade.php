{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @include('schluessel::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
