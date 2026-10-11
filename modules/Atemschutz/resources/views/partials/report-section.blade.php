{{-- Atemschutzüberwachung in the Einsatzbericht (page and print), when the module is on --}}
@php($operations = \Modules\Atemschutz\src\Models\Operation::where('report_id', $report->id)->with('teams.members')->orderBy('started_at')->get())
@if($operations->isNotEmpty() || ($print ?? false) === false)
    @if($print ?? false)
        <section>
            <h2>Atemschutzüberwachung</h2>
            <table class="grid">
                <tr><th>Trupp</th><th>Zeit</th><th>Geräteträger (Start → Ende)</th></tr>
                @foreach($operations->flatMap->teams->whereNotNull('started_at') as $team)
                    <tr><td>{{ $team->name }}</td><td>{{ $team->started_at->setTimezone('Europe/Berlin')->format('H:i') }}–{{ $team->ended_at?->setTimezone('Europe/Berlin')->format('H:i') ?? '…' }} ({{ $team->minutes() }} min)</td>
                        <td>{{ $team->members->map(fn ($m) => $m->name.' '.$m->start_pressure.'→'.($m->end_pressure ?? '…').' bar')->implode(', ') }}</td></tr>
                @endforeach
            </table>
        </section>
    @else
        <section class="mx-auto mt-4 max-w-4xl space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Atemschutzüberwachung</p>
            @forelse($operations as $op)
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span>{{ $op->title }} · {{ $op->teams->whereNotNull('started_at')->count() }} Trupps{{ $op->isRunning() ? ' · läuft' : '' }}</span>
                    <span class="flex gap-3 text-xs">
                        @can('atemschutz-monitor')<a href="{{ route('atemschutz.board', $op) }}" class="text-fw-navy underline">Überwachung</a>@endcan
                        <a href="{{ route('atemschutz.pdf', $op) }}" target="_blank" class="text-fw-navy underline">Protokoll (PDF)</a>
                    </span>
                </div>
            @empty
                <p class="text-sm text-gray-500">Für diesen Einsatz läuft keine Atemschutzüberwachung.</p>
            @endforelse
            @can('atemschutz-monitor')
                @if($operations->doesntContain(fn ($op) => $op->isRunning()))
                    <a href="{{ route('atemschutz.index', ['bericht' => $report->id]) }}" class="inline-block text-sm text-fw-navy underline">Atemschutzüberwachung für diesen Einsatz starten</a>
                @endif
            @endcan
        </section>
    @endif
@endif
