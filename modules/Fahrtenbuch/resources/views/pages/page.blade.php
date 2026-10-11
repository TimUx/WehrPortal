{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @unless(request()->boolean('kiosk'))@include('fahrtenbuch::partials.nav')@endunless
    @livewire($livewireComponent, $params ?? [])
</x-layouts.app>
