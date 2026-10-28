{{-- In the Einsatzbericht: the exposures of this Einsatz and recording them for the crew --}}
@php($exPropose = \Modules\Expositionen\src\Services\ExposureService::canPropose(auth()->user(), $report))
@php($exCount = $exPropose ? \Modules\Expositionen\src\Models\Exposure::where('report_id', $report->id)->where('ext_location_id', $report->ext_location_id)->count() : null)
@php($exOwn = \Modules\Expositionen\src\Models\Exposure::where('report_id', $report->id)->where('user_id', auth()->id())->first())
@if($exPropose || $exOwn || $report->crew()->where('user_id', auth()->id())->exists())
    <section class="mt-4 space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900">Gefahrstoff-Exposition</h2>
        @if(session('expositionen-crew'))<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('expositionen-crew') }}</p>@endif
        @if($exPropose)<p class="text-sm text-gray-600">{{ $exCount ? $exCount.' Exposition'.($exCount === 1 ? '' : 'en').' zu diesem Einsatz erfasst.' : 'Noch keine Exposition zu diesem Einsatz erfasst.' }}</p>@endif
        <div class="flex flex-wrap gap-3 text-sm">
            @if($exPropose)<a href="{{ route('expositionen.crew', $report) }}" class="rounded-lg bg-fw-navy px-3 py-1.5 font-semibold text-white">Für die Besatzung erfassen</a>@endif
            @if($exOwn)<a href="{{ route('expositionen.edit', $exOwn) }}" class="py-1.5 text-fw-navy underline">Meine Exposition</a>
            @else<a href="{{ route('expositionen.create', ['einsatz' => $report->id]) }}" class="py-1.5 text-fw-navy underline">Eigene Exposition erfassen</a>@endif
        </div>
    </section>
@endif
