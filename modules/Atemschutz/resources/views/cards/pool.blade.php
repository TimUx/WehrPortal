<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Atemschutz</p>
    @if($pool)
        <p class="mt-1 flex flex-wrap gap-x-3 text-sm">
            <span class="font-semibold text-green-700">{{ $pool['ready'] }} einsatzbereit</span>
            @if($pool['soon'])<span class="text-amber-800">{{ $pool['soon'] }} läuft bald ab</span>@endif
            @if($pool['not_ready'])<span class="text-red-700">{{ $pool['not_ready'] }} nicht einsatzbereit</span>@endif
            @if($pool['csa_ready'])<span class="text-gray-700">{{ $pool['csa_ready'] }} CSA-bereit</span>@endif
        </p>
    @endif
    @if($state)
        <p class="mt-1 text-sm {{ $state['ready'] ? ($state['soon'] ? 'text-amber-800' : 'text-green-700') : 'text-red-700' }}">
            Ich: {{ $state['ready'] ? ($state['soon'] ? 'einsatzbereit, Frist läuft bald ab' : 'einsatzbereit') : 'nicht einsatzbereit – '.implode(', ', $state['reasons']) }}
        </p>
    @endif
    <a href="{{ $pool ? route('atemschutz.index') : route('atemschutz.personnel-file') }}" class="text-sm text-fw-navy underline">Atemschutz</a>
</div>
