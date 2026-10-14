{{-- Every page of the module: the tabs and one Livewire component --}}
<x-layouts.app :title="$title">
    @if(\Modules\Wartung\src\Services\WartungService::ready())
        @livewire($livewireComponent, $params ?? [])
    @else
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Das Wartungsmanagement arbeitet mit den Geräten der <strong>Ausrüstungsverwaltung</strong>. Bitte zuerst dieses Modul einschalten (Admin → Module).</p>
    @endif
</x-layouts.app>
