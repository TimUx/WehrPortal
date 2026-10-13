@php($L = \Modules\Fuehrerscheine\src\Models\License::class)
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Führerscheine prüfen</h1>
        <p class="text-sm text-gray-500">Die Kameraden reichen Klassen und Fotos ein; du gleichst ab und bestätigst. Ein abgelaufener Führerschein lässt sich nicht bestätigen, eine abgelaufene Klasse schon (der Kamerad entscheidet, ob er sie verlängert). Mit der Entscheidung werden die Fotos gelöscht.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @forelse($pending as $l)
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-white p-4 shadow-sm" wire:key="p-{{ $l->id }}">
            <p class="font-semibold text-gray-900">{{ $l->user->name }} <span class="text-xs font-normal text-gray-500">eingereicht {{ $l->submitted_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span></p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach($l->photos->sortBy(fn ($p) => $p->side === 'front' ? 0 : 1) as $p)
                    <a href="{{ route('fuehrerscheine.photo', $p->id) }}" target="_blank"><img src="{{ route('fuehrerscheine.photo', $p->id) }}" alt="{{ $p->side === 'front' ? 'Vorderseite' : 'Rückseite' }}" class="max-h-64 w-full rounded-lg border object-contain"></a>
                @endforeach
            </div>
            <p class="text-sm text-gray-700">Karte gültig bis: <b>{{ $l->document_valid_until?->format('d.m.Y') ?? 'ohne Angabe' }}</b>@if($l->documentExpired()) <span class="font-semibold text-red-700">– abgelaufen</span>@endif</p>
            <p class="text-sm text-gray-700">Klassen: {{ collect($l->classes)->map(fn ($c) => ($L::FIRE[$c['class']] ?? $c['class']).($c['valid_until'] ? ' bis '.\Illuminate\Support\Carbon::parse($c['valid_until'])->format('d.m.Y') : ''))->implode(', ') }}</p>
            <input type="text" wire:model="reasons.{{ $l->id }}" maxlength="500" placeholder="Bemerkung bzw. was nicht passt (beim Zurückweisen Pflicht)" class="w-full rounded-lg border-gray-300 text-sm">
            @error('license-'.$l->id)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex gap-2"><button type="button" wire:click="validateLicense({{ $l->id }})" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Stimmt – bestätigen</button>
                <button type="button" wire:click="rejectLicense({{ $l->id }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Zurückweisen</button></div>
        </section>
    @empty
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Kein Führerschein wartet auf die Prüfung.</p>
    @endforelse

    @if($matrix)
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900">Klassen je Abteilung <span class="font-normal text-gray-500">(gültig und geprüft)</span></h2>
            <div class="overflow-x-auto"><table class="mt-2 min-w-full text-sm">
                <thead class="text-left text-xs text-gray-500"><tr><th class="p-1">Abteilung</th>@foreach($classes as $c)<th class="p-1 text-right">{{ $L::FIRE[$c] ?? $c }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100">@foreach($matrix as $group => $row)<tr><td class="p-1">{{ $group }}</td>@foreach($classes as $c)<td class="p-1 text-right">{{ $row[$c] ?? '–' }}</td>@endforeach</tr>@endforeach</tbody>
            </table></div>
        </section>
    @endif

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="text-sm font-semibold text-gray-900">Alle Führerscheine</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($all as $l)
                <li class="flex flex-wrap justify-between gap-2 py-1.5"><span>{{ $l->user->name }} <span class="text-xs text-gray-500">{{ collect($l->validClasses())->map(fn ($c) => $L::FIRE[$c] ?? $c)->implode(', ') }}</span></span>
                    <span class="text-xs {{ $l->usable() && ! $l->checkDue() ? 'text-green-700' : 'text-amber-700' }}">{{ $l->statusLabel() }}{{ $l->next_check_on && $l->status === 'validated' ? ' · nächste Prüfung '.$l->next_check_on->format('d.m.Y') : '' }}</span></li>
            @empty
                <li class="py-1.5 text-gray-500">Noch keine.</li>
            @endforelse
        </ul>
        <form wire:submit="saveInterval" class="mt-3 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-3 text-sm">
            <label class="text-gray-700">Erneut prüfen alle<input type="number" min="1" max="36" wire:model="interval" class="ml-2 w-20 rounded-lg border-gray-300 text-sm"> Monate</label>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Speichern</button>
        </form>
    </section>
</div>
