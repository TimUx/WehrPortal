@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'bg-white rounded-2xl border border-gray-100 shadow-sm p-5')
<div class="space-y-6">
    {{-- Header --}}
    <div>
        <a href="{{ route('schichtplan.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Schichtplan</a>
        <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $plan->title }}</h1>
                <p class="text-sm text-gray-500">{{ $plan->timeLabel() }}@if($plan->location) · {{ $plan->location }}@endif</p>
                @include('schichtplan::partials.event-link', ['plan' => $plan])
                <p class="mt-1 text-xs text-gray-500">
                    <span class="px-2 py-0.5 rounded-full font-semibold {{ $plan->isPublished() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $plan->isPublished() ? 'Veröffentlicht' : 'Entwurf' }}</span>
                    @if($plan->mailed_at) · zuletzt verschickt {{ $plan->mailed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('schichtplan.print', $plan) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Druckansicht</a>
                <a href="{{ route('schichtplan.csv', $plan) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">CSV</a>
                @if($plan->isSelfSignup())
                    <a href="{{ route('schichtplan.signup', $plan) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Eintragung{{ $requests ? ' ('.$requests.' Anfrage'.($requests === 1 ? '' : 'n').')' : '' }}</a>
                @endif
                @if($plan->isPublished())
                    <button type="button" wire:click="sendMail" wire:confirm="Den aktuellen Plan an alle {{ $participants->count() }} Teilnehmer schicken?" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Per E-Mail senden</button>
                @else
                    @if($plan->isSelfSignup())
                        <button type="button" wire:click="publish" wire:confirm="Veröffentlichen und die Zielgruppe zum Eintragen einladen?" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Veröffentlichen &amp; einladen</button>
                    @else
                        <button type="button" wire:click="publish" wire:confirm="Veröffentlichen und den Plan an alle {{ $participants->count() }} Teilnehmer schicken?" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Veröffentlichen &amp; senden</button>
                    @endif
                @endif
            </div>
        </div>
        @if($flash)
            <p class="mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>
        @endif
    </div>

    {{-- Settings --}}
    <details class="{{ $card }}">
        <summary class="cursor-pointer text-sm font-semibold text-gray-900">Ereignis bearbeiten</summary>
        <form wire:submit="saveSettings" class="mt-4 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="block text-sm text-gray-700">Wofür<input type="text" wire:model="title" class="mt-1 w-full {{ $input }}"></label>
                <label class="block text-sm text-gray-700">Ort<input type="text" wire:model="location" class="mt-1 w-full {{ $input }}"></label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="block text-sm text-gray-700">Datum<input type="date" wire:model="date" class="mt-1 w-full {{ $input }}"></label>
                <label class="block text-sm text-gray-700">Beginn<input type="time" wire:model="startTime" class="mt-1 w-full {{ $input }}"></label>
                <label class="block text-sm text-gray-700">Ende<input type="time" wire:model="endTime" class="mt-1 w-full {{ $input }}"></label>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="block text-sm text-gray-700">Schichtlänge (Minuten)<input type="number" min="15" max="720" step="15" wire:model="shiftMinutes" class="mt-1 w-full {{ $input }}"></label>
                <label class="block text-sm text-gray-700">Höchstens am Stück<input type="number" min="1" max="24" wire:model="maxConsecutive" class="mt-1 w-full {{ $input }}"></label>
                <label class="block text-sm text-gray-700">Abteilung
                    <select wire:model="groupId" class="mt-1 w-full {{ $input }}"><option value="">–</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                </label>
            </div>
            @error('shiftMinutes')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @include('schichtplan::partials.signup-settings')
            <p class="text-xs text-gray-500">Ändern sich Zeiten oder Schichtlänge, werden die Zeitblöcke neu angelegt und die Einteilung geleert.</p>
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Speichern</button>
        </form>
    </details>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Stations --}}
        <section class="{{ $card }} space-y-3">
            <h2 class="text-sm font-semibold text-gray-900">Stationen</h2>
            @forelse($stations as $station)
                <div class="flex flex-wrap items-center gap-2" wire:key="st-{{ $station->id }}">
                    <input type="text" wire:model="stationEdits.{{ $station->id }}.name" class="flex-1 min-w-0 {{ $input }}">
                    <label class="inline-flex items-center gap-1 text-xs text-gray-500">Personen
                        <input type="number" min="1" max="20" wire:model="stationEdits.{{ $station->id }}.capacity" class="w-16 {{ $input }}">
                    </label>
                    <button type="button" wire:click="saveStation({{ $station->id }})" class="text-sm text-fw-navy underline">Speichern</button>
                    <button type="button" wire:click="removeStation({{ $station->id }})" wire:confirm="Station „{{ $station->name }}“ mit ihren Einteilungen löschen?" class="text-sm text-red-600 underline">Löschen</button>
                    <label class="flex w-full items-center gap-2 text-xs text-gray-500">Verantwortlich (ganzer Abend)
                        <select wire:model="stationLeads.{{ $station->id }}" wire:change="saveStationLead({{ $station->id }})" class="flex-1 min-w-0 {{ $input }} py-1">
                            <option value="">– niemand –</option>
                            @foreach($leadOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </label>
                    @error('stationLeads.'.$station->id)<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
            @empty
                <p class="text-sm text-gray-500">Noch keine Stationen.</p>
            @endforelse

            <form wire:submit="addStation" class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3">
                <input type="text" wire:model="newStationName" placeholder="z. B. Fritteuse, Kasse, Grill" class="flex-1 min-w-0 {{ $input }}">
                <label class="inline-flex items-center gap-1 text-xs text-gray-500">Personen
                    <input type="number" min="1" max="20" wire:model="newStationCapacity" class="w-16 {{ $input }}">
                </label>
                <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Hinzufügen</button>
            </form>
            @error('newStationName')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 text-sm">
                @if($templates->isNotEmpty())
                    <select wire:model="templateId" class="{{ $input }}"><option value="">Vorlage wählen …</option>@foreach($templates as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    <button type="button" wire:click="applyTemplate" class="text-fw-navy underline">Stationen übernehmen</button>
                @endif
                @if($stations->isNotEmpty())
                    <input type="text" wire:model="templateName" placeholder="Name der Vorlage" class="{{ $input }}">
                    <button type="button" wire:click="saveTemplate" class="text-fw-navy underline">Als Vorlage speichern</button>
                @endif
            </div>
            @error('templateName')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </section>

        {{-- Participants --}}
        <section class="{{ $card }} space-y-3">
            <h2 class="text-sm font-semibold text-gray-900">Teilnehmer ({{ $participants->count() }})</h2>

            <div class="flex flex-wrap items-center gap-2">
                <select wire:model="addGroupId" class="{{ $input }}"><option value="">Abteilung …</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <button type="button" wire:click="addGroup" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Ganze Abteilung hinzufügen</button>
            </div>
            @if($candidates->isNotEmpty())
                <div class="flex flex-wrap items-start gap-2">
                    <select multiple wire:model="addUserIds" size="5" class="flex-1 min-w-0 {{ $input }}">
                        @foreach($candidates as $candidate)<option value="{{ $candidate->id }}">{{ $candidate->name.($candidate->groups->isNotEmpty() ? ' ('.$candidate->groups->pluck('name')->implode(', ').')' : '') }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="addParticipants" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Hinzufügen</button>
                </div>
                <p class="text-xs text-gray-500">Mehrere mit Strg/Cmd auswählen. Angeboten werden Kameraden mit Zugang zum Schichtplan.</p>
            @endif

            <div class="divide-y divide-gray-100 border-t border-gray-100">
                @foreach($participants as $participant)
                    <div class="py-2" wire:key="p-{{ $participant->user_id }}" x-data="{ open: false }">
                        <div class="flex items-center justify-between gap-2 text-sm">
                            <span class="min-w-0">
                                <span class="font-medium text-gray-900">{{ $participant->user->name }}</span>
                                @if($participant->user->groups->isNotEmpty())<span class="text-xs text-gray-500"> · {{ $participant->user->groups->pluck('name')->implode(', ') }}</span>@endif
                            </span>
                            <span class="flex items-center gap-3 text-xs">
                                @if($participant->available_from || $participant->available_until)
                                    <span class="text-gray-500">{{ $participant->available_from ? 'ab '.$participant->available_from->setTimezone('Europe/Berlin')->format('H:i') : '' }} {{ $participant->available_until ? 'bis '.$participant->available_until->setTimezone('Europe/Berlin')->format('H:i') : '' }}</span>
                                @endif
                                <button type="button" x-on:click="open = ! open" class="text-fw-navy underline">Verfügbarkeit</button>
                                <button type="button" wire:click="removeParticipant({{ $participant->user_id }})" class="text-red-600 underline">Entfernen</button>
                            </span>
                        </div>
                        <div x-show="open" x-cloak class="mt-2 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                            <label>ab <input type="datetime-local" wire:model="availability.{{ $participant->user_id }}.from" class="{{ $input }}"></label>
                            <label>bis <input type="datetime-local" wire:model="availability.{{ $participant->user_id }}.until" class="{{ $input }}"></label>
                            <button type="button" wire:click="saveAvailability({{ $participant->user_id }})" class="text-fw-navy underline">Speichern</button>
                        </div>
                        @error('availability.'.$participant->user_id)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Distribution --}}
    <section class="{{ $card }} space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900">Einteilung</h2>
                <p class="text-xs text-gray-500">Jeder soll jede Station einmal machen; danach wird gleichmäßig weiter verteilt. Von Hand gesetzte Plätze bekommen ein Schloss und bleiben beim Neuverteilen stehen.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="distribute" @disabled($stations->isEmpty() || $participants->isEmpty()) class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">{{ $stats['people']->sum('total') > 0 ? 'Neu verteilen' : 'Plan füllen' }}</button>
                @if($stats['people']->sum('total') > 0)
                    <button type="button" wire:click="clearAll" wire:confirm="Alle Einteilungen, auch die fixierten, entfernen?" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Leeren</button>
                @endif
            </div>
        </div>

        @if($stations->isEmpty() || $blocks->isEmpty())
            <p class="text-sm text-gray-500">Lege zuerst Stationen an.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-3">Zeit</th>
                            @foreach($stations as $station)<th class="py-2 pr-3">{{ $station->name }}@if($station->lead)<span class="block font-normal normal-case text-fw-navy">Verantw.: {{ $station->lead->name }}</span>@endif</th>@endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($blocks as $slot)
                            <tr wire:key="row-{{ $slot->id }}">
                                <td class="py-2 pr-3 whitespace-nowrap font-medium text-gray-900 align-top">{{ $slot->label() }}</td>
                                @foreach($stations as $station)
                                    @php($seats = $cells->get($slot->id.'-'.$station->id, collect())->values())
                                    <td class="py-2 pr-3 align-top">
                                        <div class="space-y-1">
                                            @for($i = 0; $i < $station->capacity; $i++)
                                                @php($seat = $seats->get($i))
                                                <div class="flex items-center gap-1" wire:key="seat-{{ $slot->id }}-{{ $station->id }}-{{ $i }}-{{ $seat?->id }}-{{ $seat?->user_id }}-{{ (int) $seat?->locked }}">
                                                    <select wire:change="setSeat({{ $slot->id }}, {{ $station->id }}, {{ $seat?->user_id ?? 'null' }}, $event.target.value)"
                                                            class="w-40 rounded-md border px-2 py-1 text-sm {{ $seat ? 'border-gray-300' : 'border-red-300 bg-red-50' }}">
                                                        <option value="">– frei –</option>
                                                        @foreach(($availableFor[$slot->id] ?? []) + ($seat ? [$seat->user_id => $seat->user->name] : []) as $id => $name)
                                                            <option value="{{ $id }}" @selected($seat?->user_id === $id)>{{ $name }}</option>
                                                        @endforeach
                                                    </select>
                                                    @if($seat)
                                                        <button type="button" wire:click="toggleLock({{ $seat->id }})" title="{{ $seat->locked ? 'Fixiert – bleibt beim Neuverteilen' : 'Fixieren' }}" class="text-base {{ $seat->locked ? 'text-fw-red' : 'text-gray-300 hover:text-gray-500' }}">{{ $seat->locked ? '🔒' : '🔓' }}</button>
                                                    @endif
                                                </div>
                                            @endfor
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Wählst du jemanden, der im selben Block schon an einer anderen Station steht, tauschen die beiden.</p>
        @endif
    </section>

    {{-- Stats --}}
    @if($participants->isNotEmpty() && $stats['people']->sum('total') > 0)
        <section class="{{ $card }} space-y-3">
            <h2 class="text-sm font-semibold text-gray-900">Übersicht</h2>
            <p class="text-sm text-gray-600">
                Schichten pro Person: {{ $stats['min'] }} bis {{ $stats['max'] }}.
                @if($stats['gaps']->isEmpty())
                    <span class="text-green-700 font-semibold">Alle Plätze sind besetzt.</span>
                @else
                    <span class="text-red-700 font-semibold">{{ $stats['gaps']->sum('missing') }} Platz/Plätze unbesetzt</span> – zu wenige Teilnehmer für alle Stationen in diesen Blöcken:
                @endif
            </p>
            @if($stats['gaps']->isNotEmpty())
                <ul class="text-sm text-red-700 list-disc pl-5">
                    @foreach($stats['gaps']->take(20) as $gap)<li>{{ $gap['slot']->label() }} · {{ $gap['station']->name }} ({{ $gap['missing'] }})</li>@endforeach
                </ul>
            @endif
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-xs uppercase tracking-wide text-gray-500"><th class="py-1 pr-3">Name</th><th class="py-1 pr-3">Schichten</th><th class="py-1">Station noch nicht gemacht</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($stats['people'] as $person)
                            <tr><td class="py-1 pr-3">{{ $person['user']->name }}</td><td class="py-1 pr-3">{{ $person['total'] }}</td><td class="py-1 text-gray-500">{{ $person['missing']->implode(', ') ?: '–' }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="text-right">
        <button type="button" wire:click="delete" wire:confirm="Plan „{{ $plan->title }}“ endgültig löschen?" class="text-sm text-red-600 underline">Plan löschen</button>
    </div>
</div>
