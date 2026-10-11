<x-layouts.app :title="'Einsatz '.$report->number">
    <livewire:einsatzbericht.report-editor :report="$report" :key="'report-'.$report->id" />
</x-layouts.app>
