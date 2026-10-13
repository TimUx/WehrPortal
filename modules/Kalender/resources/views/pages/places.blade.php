<x-layouts.app title="Orte & Kleidung">
    <a href="{{ route('kalender.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Kalender</a>
    <livewire:catalog.places-and-dress ability="manage" :argument="\Modules\Kalender\src\Models\Event::class" />
</x-layouts.app>
