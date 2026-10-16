{{-- Every page of the module: the Modulleiste and one Livewire component --}}
<x-layouts.app :title="$title">
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
