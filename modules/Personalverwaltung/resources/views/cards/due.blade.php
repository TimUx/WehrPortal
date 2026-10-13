<a href="{{ route($requests ? 'personalverwaltung.requests' : 'personalverwaltung.honors') }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    @if($requests)<p class="text-sm font-semibold text-amber-900">{{ $requests }} offene{{ $requests === 1 ? 'r' : '' }} Änderungswunsch{{ $requests === 1 ? '' : 'e' }}</p>@endif
    @if($due)<p class="text-sm font-semibold text-amber-900">{{ $due }} Ehrung{{ $due === 1 ? '' : 'en' }} {{ now('Europe/Berlin')->year }} fällig</p>@endif
    @if($cards)<p class="text-sm font-semibold text-amber-900">{{ $cards }} Dienstausweis{{ $cards === 1 ? '' : 'e' }} läuft bald ab</p>@endif
    <p class="text-xs text-amber-800">Personalverwaltung</p>
</a>
