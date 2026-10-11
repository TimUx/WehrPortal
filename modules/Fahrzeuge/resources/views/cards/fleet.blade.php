<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fahrzeuge</p>
    <p class="mt-1 flex flex-wrap gap-x-3 text-sm">
        <span class="font-semibold text-green-700">{{ $states['green'] ?? 0 }} von {{ $total }} einsatzbereit</span>
        @if($states['blue'] ?? 0)<span class="text-blue-700">{{ $states['blue'] }} im Einsatz / gebucht</span>@endif
        @if($states['amber'] ?? 0)<span class="text-amber-800">{{ $states['amber'] }} bedingt</span>@endif
        @if($states['red'] ?? 0)<span class="text-red-700">{{ $states['red'] }} nicht verfügbar</span>@endif
    </p>
    @if($overdue)<p class="text-sm font-semibold text-red-700">{{ $overdue }} Frist{{ $overdue === 1 ? '' : 'en' }} überfällig</p>@endif
    <a href="{{ route('fahrzeuge.index') }}" class="text-sm text-fw-navy underline">Fahrzeugverwaltung</a>
</div>
