<div class="space-y-4">
    @if(! $state)
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-600 shadow-sm">Du bist nicht im Atemschutz-Pool. Der Atemschutzwart nimmt Geräteträger auf.</p>
    @else
        <section class="rounded-2xl border bg-white p-4 shadow-sm {{ $state['ready'] ? 'border-green-200' : 'border-red-300' }}">
            <p class="text-sm font-semibold {{ $state['ready'] ? ($state['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">
                {{ $state['ready'] ? ($state['soon'] ? 'Einsatzbereit – eine Frist läuft bald ab' : 'Einsatzbereit unter Atemschutz') : 'Nicht einsatzbereit: '.implode(', ', $state['reasons']) }}
            </p>
            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[14rem_1fr]">
                @foreach($state['items'] as $key => $item)
                    <dt class="text-gray-500">{{ \Modules\Atemschutz\src\Services\ReadinessService::ITEMS[$key] }}</dt>
                    <dd class="{{ $item['ok'] ? 'text-gray-900' : 'text-red-700' }}">
                        @if($key === 'agt'){{ $item['ok'] ? 'ja' : 'nein' }}@else{{ $item['until'] ? ($item['ok'] ? 'gültig bis ' : 'abgelaufen am ').$item['until']->format('d.m.Y') : 'fehlt' }}@endif
                        @if($item['last'] && $key !== 'g263')<span class="text-xs text-gray-500"> (zuletzt {{ $item['last']->format('d.m.Y') }})</span>@endif
                    </dd>
                @endforeach
            </dl>
            @include('atemschutz::partials.csa-status', ['csa' => $state['csa']])
            <div class="mt-2 border-t border-gray-100 pt-2">@include('atemschutz::partials.wear-time', ['wear' => $wear])</div>
            <a href="{{ route('credentials') }}" class="mt-2 inline-block text-sm text-fw-navy underline">Atemschutzpass zum Vorzeigen →</a>
        </section>
    @endif

    @if($missions->isNotEmpty() || $reportMissions->isNotEmpty())
        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Unter Atemschutz</p>
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @foreach($missions as $m)
                    <div class="p-3 text-sm">
                        <p class="text-gray-900">{{ $m->team->operation->title }}</p>
                        <p class="text-xs text-gray-500">{{ $m->team->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $m->team->name }} · {{ $m->team->minutes() }} min · {{ $m->start_pressure }}→{{ $m->end_pressure ?? '…' }} bar</p>
                    </div>
                @endforeach
                @foreach($reportMissions as $r)
                    <div class="p-3 text-sm">
                        <p class="text-gray-900">Einsatz {{ $r->number }}{{ $r->keyword ? ' · '.$r->keyword : '' }}</p>
                        <p class="text-xs text-gray-500">{{ \Illuminate\Support\Carbon::parse($r->alarmed_at)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · laut Einsatzbericht, ohne Überwachung</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($trainings->isNotEmpty())
        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Nachweise</p>
            <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
                @foreach($trainings as $t)<li class="p-3">{{ $t->done_on->format('d.m.Y') }} · {{ $t->kindLabel() }}{{ $t->csa ? ' (CSA)' : '' }}{{ $t->minutes ? ' · '.$t->minutes.' min' : '' }}{{ $t->notes ? ' · '.$t->notes : '' }}</li>@endforeach
            </ul>
        </section>
    @endif
</div>
