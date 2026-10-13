<a href="{{ route($pending ? 'fuehrerscheine.review' : 'fuehrerscheine.index') }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    <p class="text-xs font-semibold uppercase tracking-wide text-amber-800">Führerschein</p>
    @if($own)<p class="text-sm font-semibold text-amber-900">{{ $own }}</p>@endif
    @if($pending)<p class="text-sm text-amber-900">{{ $pending }} Führerschein{{ $pending === 1 ? '' : 'e' }} warten auf die Prüfung</p>@endif
</a>
