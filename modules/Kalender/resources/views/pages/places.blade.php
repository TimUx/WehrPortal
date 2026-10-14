<x-layouts.app title="Orte & Kleidung">
    <a href="{{ route('kalender.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Kalender</a>
    <livewire:catalog.places-and-dress ability="manage" :argument="\Modules\Kalender\src\Models\Event::class" />
</x-layouts.app>
