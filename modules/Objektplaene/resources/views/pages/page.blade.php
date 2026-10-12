{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @include('objektplaene::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
