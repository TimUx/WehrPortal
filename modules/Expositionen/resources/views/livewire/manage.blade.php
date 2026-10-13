@php($sel = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Expositionen – Meldeliste</h1>
        <p class="text-sm text-gray-500">Erfasste Expositionen werden in der <b>Zentralen Expositionsdatenbank (ZED)</b> der DGUV gemeldet (zed.dguv.de). Eine direkte Schnittstelle bietet die DGUV nicht an: Die CSV enthält alle Angaben für die Eingabe dort; danach hier auswählen und „als gemeldet vermerken“. Aufbewahrt wird jede Exposition 40 Jahre; vorher lässt sie sich nicht löschen.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="status" class="{{ $sel }}" aria-label="Status">
            <option value="">Alle</option>
            @foreach(\Modules\Expositionen\src\Models\Exposure::STATUSES as $k => $l)<option value="{{ $k }}">{{ $l }} ({{ $counts[$k] ?? 0 }})</option>@endforeach
        </select>
        <input type="number" wire:model.live.debounce.500ms="year" placeholder="Jahr" class="{{ $sel }} w-24" aria-label="Jahr">
        <a href="{{ route('expositionen.csv', ['status' => $status, 'year' => $year]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">CSV für die ZED</a>
        <a href="{{ route('expositionen.create') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Für einen Kameraden erfassen</a>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="p-2"></th><th class="p-2">Datum</th><th class="p-2">Kamerad</th><th class="p-2">Anlass</th><th class="p-2">Gefahrstoffe</th><th class="p-2">Status</th><th class="p-2"></th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($exposures as $e)
                    <tr wire:key="x-{{ $e->id }}">
                        <td class="p-2">@if($e->status === 'recorded')<input type="checkbox" wire:model="selected" value="{{ $e->id }}" class="rounded" aria-label="auswählen">@endif</td>
                        <td class="p-2 whitespace-nowrap">{{ $e->occurred_on->format('d.m.Y') }}</td>
                        <td class="p-2">{{ $e->person_name }}@if($e->user?->status?->value === 'left')<span class="block text-[11px] text-amber-700">ausgetreten – Nachweis aushändigen</span>@endif</td>
                        <td class="p-2">{{ $e->occasion }}<span class="block text-xs text-gray-500">{{ $e->activityLabel() }}</span></td>
                        <td class="p-2 text-xs">{{ $e->substanceList() }}</td>
                        <td class="p-2 text-xs {{ $e->status === 'draft' ? 'text-amber-700' : 'text-gray-600' }}">{{ $e->statusLabel() }}@if($e->reported_on) {{ $e->reported_on->format('d.m.Y') }}@endif</td>
                        <td class="p-2 whitespace-nowrap text-xs"><a href="{{ route('expositionen.edit', $e) }}" class="text-fw-navy underline">Öffnen</a>@if($e->user)<a href="{{ route('expositionen.certificate', $e->user) }}" target="_blank" class="ml-2 text-fw-navy underline">Nachweis</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-4 text-gray-500">Keine Expositionen.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-end gap-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <label class="text-sm text-gray-700">Gemeldet am<input type="date" wire:model="reportedOn" class="{{ $sel }} mt-1 block"></label>
        <label class="text-sm text-gray-700">ZED-Referenz (optional)<input type="text" wire:model="reference" maxlength="100" class="{{ $sel }} mt-1 block"></label>
        <button type="button" wire:click="markReported" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">{{ count($selected) }} als gemeldet vermerken</button>
        @foreach(['selected', 'reported_on', 'exposure'] as $f)@error($f)<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
    </div>
</div>
