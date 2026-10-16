<div class="mx-auto max-w-4xl space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Gefahrenmatrix</h1>
        <p class="mt-1 text-sm text-gray-600">AAAA – CCCC – EEEE: Gefahren an der Einsatzstelle als Merkstütze erfassen.</p>
    </div>

    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="note">
        Diese Auswahl ist eine nicht gespeicherte Merkstütze. Sie leitet keine Schutzgüter, Maßnahmen oder Einsatzentscheidungen ab. Die fachliche Beurteilung bleibt der Einsatzleitung vorbehalten.
    </div>

    @foreach($groups as $group)
        <section class="space-y-3">
            <h2 class="text-base font-semibold text-gray-900">{{ $group['title'] }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach($group['hazards'] as $key => $label)
                    <button
                        type="button"
                        wire:click="toggle('{{ $key }}')"
                        wire:key="hazard-{{ $key }}"
                        aria-pressed="{{ in_array($key, $selected, true) ? 'true' : 'false' }}"
                        class="min-h-14 rounded-xl border px-4 py-3 text-left text-sm font-semibold transition {{ in_array($key, $selected, true) ? 'border-fw-navy bg-fw-navy text-white' : 'border-gray-200 bg-white text-gray-800 hover:border-fw-navy' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach

    <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" aria-live="polite">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-gray-900">Ausgewählte Gefahren</h2>
            <button type="button" wire:click="clear" class="rounded-lg px-3 py-2 text-sm font-semibold text-fw-navy underline" @disabled($selectedHazards->isEmpty())>
                Auswahl zurücksetzen
            </button>
        </div>
        @if($selectedHazards->isEmpty())
            <p class="mt-3 text-sm text-gray-500">Noch keine Gefahren ausgewählt.</p>
        @else
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach($selectedHazards as $hazard)
                    <li wire:key="selected-{{ $loop->index }}" class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-800">{{ $hazard }}</li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
