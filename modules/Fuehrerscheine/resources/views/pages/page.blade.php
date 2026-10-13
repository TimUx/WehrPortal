<x-layouts.app :title="$title">
    @include('fuehrerscheine::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
