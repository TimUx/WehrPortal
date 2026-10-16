@php
    $user = auth()->user();
    $access = app(\Extensions\Locations\Services\LocationAccess::class);
    $locations = $user ? \Extensions\Locations\Models\Location::query()->active()->whereIn('id', $access->visibleLocationIds($user))->orderBy('sort_order')->orderBy('name')->get() : collect();
    $current = $user ? $access->currentLocationId($user) : null;
@endphp
@if ($locations->count() > 1)
    <form method="POST" action="{{ route('locations.switch') }}" style="padding: 0.5rem 1rem;">
        @csrf
        <label style="font-size: 0.75rem; opacity: 0.7;">Standort</label>
        <select name="location_id" onchange="this.form.submit()" style="width: 100%;">
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected($location->id === $current)>{{ $location->name }}</option>
            @endforeach
        </select>
    </form>
@endif
