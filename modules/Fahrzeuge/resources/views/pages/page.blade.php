{{-- Every page of the module: one Livewire component --}}
<x-layouts.app :title="$title">
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
