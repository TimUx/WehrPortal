<x-layouts.app title="Orte & Kleidung">
    <a href="{{ route('uebungsdienste.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Übungsdienstplaner</a>
    <livewire:catalog.places-and-dress ability="plan-uebungsdienste" />
</x-layouts.app>
