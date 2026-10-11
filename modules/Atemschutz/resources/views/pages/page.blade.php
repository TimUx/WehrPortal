{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @include('atemschutz::partials.nav')
    @livewire($livewireComponent)
</x-layouts.app>
