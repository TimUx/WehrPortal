@if(config('locations.enabled') && auth()->check())
    @php
        $context = app(\Extensions\Locations\Services\LocationContext::class)->forUser(auth()->user());
        $contextId = $contextId ?? 'admin-location';
        $currentLocation = $context['locations']->firstWhere('id', $context['current']);
    @endphp
    <div style="padding: 0.5rem;">
        <p><strong>Standort für neue Datensätze:</strong>
            @if($currentLocation?->crestUrl())
                <img src="{{ $currentLocation->crestUrl() }}" alt="Wappen {{ $currentLocation->name }}" style="display: inline-block; vertical-align: middle; width: 2rem; height: 2rem; object-fit: contain; margin-inline: 0.25rem;">
            @endif
            {{ $currentLocation?->name ?? 'Kein aktiver berechtigter Standort' }}
        </p>
        <p id="{{ $contextId }}-help" style="font-size: 0.875rem;">
            @if($context['globalAdmin'])
                Globaler Admin: Zugriff auf alle Standortdaten. Die Auswahl ist nur die Vorgabe für neue Datensätze.
            @else
                Die Auswahl ist nur die Vorgabe für neue Datensätze. Berechtigungen und sichtbare Daten richten sich weiterhin nach deinen Mitgliedschaften und Rollen.
            @endif
        </p>
        @if($context['locations']->count() > 1)
            <form method="POST" action="{{ route('locations.switch') }}">
                @csrf
                <label for="{{ $contextId }}-select">Standort für neue Datensätze wählen</label>
                <select id="{{ $contextId }}-select" name="location_id" aria-describedby="{{ $contextId }}-help" style="max-width: 100%; color: inherit; background: inherit;">
                    @foreach($context['locations'] as $location)
                        <option value="{{ $location->id }}" @selected($location->id === $context['current'])>{{ $location->name }}</option>
                    @endforeach
                </select>
                <button type="submit" style="padding: 0.375rem 0.75rem; border: 1px solid currentColor; border-radius: 0.25rem;">Standort übernehmen</button>
            </form>
        @endif
    </div>
@endif
