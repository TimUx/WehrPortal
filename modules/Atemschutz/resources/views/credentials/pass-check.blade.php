<p style="font-weight:600;color:{{ $state['ready'] ? '#065f46' : '#991b1b' }}">{{ $state['ready'] ? 'Einsatzbereit unter Atemschutz' : 'Nicht einsatzbereit: '.implode(', ', $state['reasons']) }}</p>
<dl>
    @foreach($state['items'] as $key => $item)
        <dt>{{ \Modules\Atemschutz\src\Services\ReadinessService::ITEMS[$key] }}</dt>
        <dd>@if($key === 'agt'){{ $item['ok'] ? 'ja' : 'nein' }}@else{{ $item['until'] ? ($item['ok'] ? 'bis ' : 'abgelaufen ').$item['until']->format('d.m.Y') : 'fehlt' }}@endif</dd>
    @endforeach
</dl>
@include('atemschutz::partials.csa-status', ['csa' => $state['csa'], 'plain' => true])
