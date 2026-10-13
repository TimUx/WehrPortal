@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Fahrzeuge</h1>
            <p class="text-sm text-gray-500">Alle Fahrzeuge mit Status und Verfügbarkeit – dieselbe Liste wie in Fahrzeugbuchung und Einsatzbericht.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('inventory') }}" class="text-sm text-fw-navy underline">Bestand & Ersatzbeschaffung</a>
            @if($canManage)<a href="{{ route('fahrzeuge.create') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Neues Fahrzeug</a>@endif
        </div>
    </div>

    @if($assignments->isNotEmpty())
        <section class="space-y-2 rounded-2xl border-2 border-blue-300 bg-blue-50 p-4">
            <p class="text-sm font-semibold text-blue-900">Im Einsatz</p>
            @foreach($assignments->groupBy('report_id') as $rows)
                <p class="text-sm text-blue-900"><strong>{{ $rows->first()['keyword'] ?: 'Einsatz '.$rows->first()['number'] }}</strong> ({{ $rows->first()['number'] }}):
                    {{ $rows->map(fn ($r) => $r['name'].' ('.$r['crew'].' Pers.'.($r['departed_at'] ? ', ab '.$r['departed_at']->setTimezone('Europe/Berlin')->format('H:i') : '').')')->implode(' · ') }}</p>
            @endforeach
        </section>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <select wire:model.live="category" class="{{ $select }}" aria-label="Fahrzeugart">
            <option value="">Alle Fahrzeugarten</option>
            @foreach(\Modules\Fahrzeuge\src\Models\Profile::CATEGORIES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="showDecommissioned" class="rounded"> auch außer Dienst</label>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @forelse($vehicles as $vehicle)
            @php($profile = $profiles[$vehicle->id] ?? null)
            @php($state = $states[$vehicle->id])
            <a href="{{ route('fahrzeuge.show', $vehicle) }}" class="flex gap-3 rounded-2xl border border-gray-100 bg-white p-3 shadow-sm hover:border-fw-navy" wire:key="v-{{ $vehicle->id }}">
                @if($profile?->photo_updated_at)
                    <img src="{{ route('fahrzeuge.photo', ['vehicle' => $vehicle, 'v' => $profile->photo_updated_at->timestamp]) }}" alt="" class="h-20 w-24 shrink-0 rounded-lg object-cover" loading="lazy">
                @else
                    <span class="flex h-20 w-24 shrink-0 items-center justify-center rounded-lg" style="background-color: {{ $vehicle->color }}22"><x-module-icon key="fahrzeugbuchung" class="h-8 w-8 text-gray-500" /></span>
                @endif
                <span class="min-w-0 space-y-0.5">
                    <span class="block font-semibold text-gray-900">{{ $vehicle->name }}</span>
                    <span class="block truncate text-xs text-gray-500">{{ collect([$profile?->call_sign, $profile?->license_plate])->filter()->implode(' · ') ?: ($profile?->categoryLabel() ?? '') }}</span>
                    @include('fahrzeuge::partials.state', ['state' => $state])
                    @if($state['detail'])<span class="block truncate text-xs text-gray-600">{{ $state['detail'] }}</span>@endif
                    @if($state['overdue'])<span class="block text-xs font-semibold text-red-700">{{ $state['overdue'] }} Frist{{ $state['overdue'] === 1 ? '' : 'en' }} überfällig</span>@endif
                    @if($n = (int) ($defects[$vehicle->id] ?? 0))<span class="block text-xs text-amber-800">{{ $n }} offene{{ $n === 1 ? 'r Mangel' : ' Mängel' }}</span>@endif
                </span>
            </a>
        @empty
            <p class="text-sm text-gray-500">Noch keine Fahrzeuge.@if($canManage) <a href="{{ route('fahrzeuge.create') }}" class="underline">Jetzt anlegen</a>@endif</p>
        @endforelse
    </div>

    @if($deadlines->isNotEmpty())
        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Fristen (überfällig und nächste 30 Tage) <a href="{{ route('fahrzeuge.deadlines') }}" class="ml-1 text-xs font-normal text-fw-navy underline">Fristenliste</a></p>
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @foreach($deadlines as $d)
                    <a href="{{ route('fahrzeuge.show', $d->vehicle_id) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="d-{{ $d->id }}">
                        <span>{{ $d->vehicle->name }} – {{ $d->title }}</span>
                        <span class="shrink-0 text-xs font-semibold {{ $d->isOverdue() ? 'text-red-700' : 'text-amber-800' }}">{{ $d->isOverdue() ? 'überfällig seit' : 'fällig' }} {{ $d->due_on->format('d.m.Y') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
