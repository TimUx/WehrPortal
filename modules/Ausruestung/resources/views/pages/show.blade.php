<x-layouts.app :title="$equipment->name">
    <livewire:ausruestung.equipment-detail :equipment="$equipment" :key="'eq-'.$equipment->id" />
</x-layouts.app>
