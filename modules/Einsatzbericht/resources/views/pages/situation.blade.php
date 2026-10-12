<x-layouts.app :title="'Lage '.($report->keyword ?: $report->number)">
    <div class="mx-auto max-w-2xl space-y-4">
        <div>
            <a href="{{ \Modules\Einsatzbericht\src\Services\ReportService::canLead(auth()->user()) ? route('einsatzbericht.show', $report) : route('einsatzbericht.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← {{ \Modules\Einsatzbericht\src\Services\ReportService::canLead(auth()->user()) ? 'Bericht' : 'Laufende Einsätze' }}</a>
            <h1 class="mt-1 text-xl font-bold text-gray-900">Lagemeldungen · {{ $report->keyword ?: 'Einsatz '.$report->number }}</h1>
            <p class="text-sm text-gray-500">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report->address ? ' · '.$report->address : '' }}@if($report->navigationUrl()) · <a href="{{ $report->navigationUrl() }}" target="_blank" rel="noopener" class="underline">Navigation</a>@endif</p>
        </div>
        {{-- Lagemonitor: the nearest Löschwasser, when the Wasserkarte is on --}}
        @if(app(\App\Services\ModuleRegistry::class)->isActive('wasserkarte') && auth()->user()->canUseModule('wasserkarte'))
            @include('wasserkarte::partials.report-section', ['report' => $report, 'class' => ''])
        @endif
        {{-- Lagemonitor: the plans of the object at the Einsatzstelle, when the Objektpläne are on --}}
        @if(app(\App\Services\ModuleRegistry::class)->isActive('objektplaene') && auth()->user()->canUseModule('objektplaene'))
            @include('objektplaene::partials.report-section', ['report' => $report, 'class' => ''])
        @endif
        <livewire:einsatzbericht.situation-log :report="$report" :key="'log-'.$report->id" />
    </div>
</x-layouts.app>
