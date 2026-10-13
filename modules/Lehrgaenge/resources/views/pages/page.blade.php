<x-layouts.app :title="$title">
    @include('lehrgaenge::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
