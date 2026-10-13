{{-- Atemschutz status of $member, e.g. in the Personalverwaltung --}}
@php($asWearer = \Modules\Atemschutz\src\Models\Wearer::where('user_id', $member->id)->where('is_active', true)->first())
@php($asState = $asWearer ? app(\Modules\Atemschutz\src\Services\ReadinessService::class)->forWearer($asWearer) : null)
<section class="{{ $class ?? 'space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm' }}">
    <h2 class="text-sm font-semibold text-gray-900">Atemschutz</h2>
    @if(! $asState)
        <p class="text-sm text-gray-500">Nicht im Atemschutz-Pool.</p>
    @else
        <p class="text-sm font-semibold {{ $asState['ready'] ? ($asState['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">
            {{ $asState['ready'] ? ($asState['soon'] ? 'Einsatzbereit – eine Frist läuft bald ab' : 'Einsatzbereit') : 'Nicht einsatzbereit: '.implode(', ', $asState['reasons']) }}
        </p>
        <dl class="grid grid-cols-[10rem_1fr] gap-x-3 gap-y-0.5 text-xs">
            @foreach($asState['items'] as $key => $item)
                <dt class="text-gray-500">{{ \Modules\Atemschutz\src\Services\ReadinessService::ITEMS[$key] }}</dt>
                <dd class="{{ $item['ok'] ? 'text-gray-800' : 'text-red-700' }}">@if($key === 'agt'){{ $item['ok'] ? 'ja' : 'nein' }}@else{{ $item['until'] ? ($item['ok'] ? 'bis ' : 'abgelaufen ').$item['until']->format('d.m.Y') : 'fehlt' }}@endif</dd>
            @endforeach
        </dl>
        @include('atemschutz::partials.csa-status', ['csa' => $asState['csa']])
    @endif
</section>
