{{-- Atemschutzpass on the phone: readiness, deadlines and Tragezeit of the year; $state from ReadinessService, $wear --}}
<div class="overflow-hidden rounded-2xl border bg-white shadow-sm {{ $state['ready'] ? ($state['soon'] ? 'border-amber-300' : 'border-green-300') : 'border-red-300' }}">
    <div class="flex items-center justify-between px-4 py-3 {{ $state['ready'] ? ($state['soon'] ? 'bg-amber-50' : 'bg-green-50') : 'bg-red-50' }}">
        <span class="text-sm font-semibold text-gray-900">Atemschutzpass</span>
        <span class="text-sm font-semibold {{ $state['ready'] ? ($state['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">{{ $state['ready'] ? ($state['soon'] ? 'Einsatzbereit – Frist läuft ab' : 'Einsatzbereit') : 'Nicht einsatzbereit' }}</span>
    </div>
    <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-1 p-4 text-sm">
        @foreach($state['items'] as $key => $item)
            <dt class="text-gray-500">{{ \Modules\Atemschutz\src\Services\ReadinessService::ITEMS[$key] }}</dt>
            <dd class="{{ $item['ok'] ? 'text-gray-900' : (in_array($key, \Modules\Atemschutz\src\Services\ReadinessService::BLOCKING, true) ? 'font-semibold text-red-700' : 'text-gray-500') }}">
                @if($key === 'agt'){{ $item['ok'] ? 'ja'.($item['last'] ? ' ('.$item['last']->format('d.m.Y').')' : '') : 'nein' }}@else{{ $item['until'] ? ($item['ok'] ? 'bis ' : 'abgelaufen ').$item['until']->format('d.m.Y') : 'fehlt' }}@endif
            </dd>
        @endforeach
        <dt class="text-gray-500">Tragezeit {{ $wear['year'] }}</dt>
        <dd class="text-gray-900">{{ $wear['minutes'] }} min{{ $wear['minutes'] ? ' ('.collect($wear['by'])->filter()->map(fn ($m, $k) => \Modules\Atemschutz\src\Services\ReadinessService::WEAR_KINDS[$k].' '.$m)->implode(', ').')' : '' }}</dd>
    </dl>
    @if($state['csa'])<div class="border-t border-gray-100 px-4 py-3">@include('atemschutz::partials.csa-status', ['csa' => $state['csa']])</div>@endif
</div>
