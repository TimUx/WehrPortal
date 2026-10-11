<a href="{{ route('einsatzbericht.index', ['status' => $toRelease > 0 ? 'closed' : ($active > 0 ? 'active' : 'draft')]) }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Einsatzberichte</p>
    @if($toRelease > 0)<p class="mt-1 font-semibold text-gray-900">{{ $toRelease }} {{ $toRelease === 1 ? 'Bericht wartet' : 'Berichte warten' }} auf Freigabe</p>@endif
    @if($active > 0)<p class="mt-1 text-sm font-semibold text-red-700">{{ $active }} {{ $active === 1 ? 'Einsatz ist' : 'Einsätze sind' }} aktuell</p>@endif
    @if($drafts > 0)<p class="mt-1 text-sm text-gray-700">{{ $drafts }} {{ $drafts === 1 ? 'Bericht ist' : 'Berichte sind' }} noch Entwurf</p>@endif
</a>
