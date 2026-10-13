<x-layouts.app :title="$title">
    @include('unterweisungen::partials.nav')
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
