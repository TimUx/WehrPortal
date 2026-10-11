<x-layouts.app :title="$defect->title">
    <livewire:maengelmanagement.defect-detail :defect="$defect" :key="'defect-'.$defect->id" />
</x-layouts.app>
