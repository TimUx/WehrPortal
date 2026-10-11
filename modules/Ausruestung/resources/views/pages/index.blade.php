<x-layouts.app title="Ausrüstung">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-xl font-bold text-gray-900">Meine Ausleihen</h1>
        <a href="{{ route('ausruestung.equipment') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-fw-red text-white text-sm font-semibold hover:bg-fw-red-dark">Alle Geräte</a>
    </div>
    <livewire:ausruestung.my-loans />
</x-layouts.app>
