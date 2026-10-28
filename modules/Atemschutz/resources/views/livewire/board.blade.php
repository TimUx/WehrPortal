@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($running = $operation->isRunning())
@php($ms = fn ($t) => $t ? $t->getTimestampMs() : 'null')
<div class="space-y-4" x-data="asAlarm({{ $warningCount }})" @if($running) wire:poll.5s @endif>
    {{-- A new element per count: its x-init hands the count to the alarm --}}
    <span hidden wire:key="alarm-{{ $warningCount }}" x-init="count = {{ $warningCount }}"></span>

    <a href="{{ route('atemschutz.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Übersicht</a>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">{{ \Modules\Atemschutz\src\Models\Operation::KINDS[$operation->kind] }} · seit {{ $operation->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($operation->report) · Einsatz {{ $operation->report->number }}@endif @if(config('locations.enabled') && $operation->ext_location_id)· {{ $operation->location?->name }}@endif</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $operation->title }}</h1>
        </div>
        <div class="flex flex-wrap items-center gap-3 text-sm">
            @if($running)<button type="button" x-on:click="muted = !muted" class="text-gray-500 underline" x-text="muted ? 'Ton an' : 'Ton aus'"></button>@endif
            <a href="{{ route('atemschutz.pdf', $operation) }}" target="_blank" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">PDF</a>
            @if($running)
                <button type="button" wire:click="endOperation" wire:confirm="Überwachung beenden? Danach lassen sich keine Trupps mehr eintragen." class="rounded-lg border border-red-300 bg-white px-3 py-1.5 font-semibold text-red-700">Überwachung beenden</button>
            @else
                <span class="rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700">beendet {{ $operation->ended_at->setTimezone('Europe/Berlin')->format('H:i') }}</span>
            @endif
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('operation')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    @if($unacknowledged->isNotEmpty())
        <div class="space-y-1 rounded-2xl border-2 border-red-600 bg-red-50 p-3">
            @foreach($unacknowledged as $warning)
                <div class="flex items-center justify-between gap-3 text-sm font-semibold text-red-800" wire:key="w-{{ $warning->id }}">
                    <span>{{ $teams->firstWhere('id', $warning->team_id)?->name }}: {{ $states[$warning->team_id]['warnings'][$warning->kind] }}</span>
                    <button type="button" wire:click="acknowledge({{ $warning->id }})" class="shrink-0 rounded-lg bg-red-700 px-3 py-1 text-xs text-white">Quittieren</button>
                </div>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        @forelse($teams->where('status', '!=', 'done') as $team)
            @php($state = $states[$team->id])
            <section class="space-y-3 rounded-2xl border bg-white p-4 shadow-sm {{ $state['warnings'] ? 'border-red-400' : 'border-gray-100' }}" wire:key="t-{{ $team->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-gray-900">{{ $team->name }}@if($team->csa) <span class="rounded bg-amber-100 px-1.5 py-0.5 align-middle text-xs font-semibold text-amber-900">CSA</span>@endif</h2>
                        @if($team->task)<p class="text-sm text-gray-600">{{ $team->task }}</p>@endif
                    </div>
                    @if($team->isActive())
                        <div class="text-right" wire:key="clock-{{ $team->id }}" x-data="asClock({{ $ms($team->started_at) }}, null)">
                            <p class="font-mono text-3xl font-bold {{ $state['elapsed'] >= $state['expected'] ? 'text-red-700' : 'text-gray-900' }}" x-text="text">{{ sprintf('%02d:00', $state['elapsed']) }}</p>
                            <p class="text-xs text-gray-500">von ca. {{ $state['expected'] }} min</p>
                        </div>
                    @else
                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-700">bereit</span>
                    @endif
                </div>

                <ul class="space-y-1 text-sm">
                    @foreach($team->members as $member)
                        <li class="flex items-center justify-between gap-3">
                            <span>{{ $member->name }}{{ $member->is_leader ? ' (TF)' : '' }}@if($member->override_reason)<span class="block text-xs text-amber-800">⚠ {{ $member->override_reason }}</span>@endif</span>
                            <span class="flex items-center gap-2">
                                <span class="text-xs text-gray-500">Start {{ $member->start_pressure }}</span>
                                <span class="font-semibold {{ $state['current'][$member->id] <= $state['retreat'] ? 'text-red-700' : 'text-gray-900' }}">{{ $state['current'][$member->id] }} bar</span>
                                @if($team->isActive() && $running)
                                    <input type="number" inputmode="numeric" min="0" max="330" wire:model="pressures.{{ $team->id }}.{{ $member->id }}" placeholder="bar" class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm" aria-label="Druck {{ $member->name }}">
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
                @error('pressures.'.$team->id)<p class="text-sm text-red-600">{{ $message }}</p>@enderror

                @if($team->isActive())
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-gray-600 sm:grid-cols-4">
                        <div><dt>Druckkontrolle 1/3</dt><dd class="font-semibold {{ $state['checks'][1]['done'] ? 'text-green-700' : 'text-gray-900' }}">{{ $state['checks'][1]['done'] ? 'erledigt' : 'bei '.$state['checks'][1]['at'].' min' }}</dd></div>
                        <div><dt>Druckkontrolle 2/3</dt><dd class="font-semibold {{ $state['checks'][2]['done'] ? 'text-green-700' : 'text-gray-900' }}">{{ $state['checks'][2]['done'] ? 'erledigt' : 'bei '.$state['checks'][2]['at'].' min' }}</dd></div>
                        <div><dt>Rückzugsdruck</dt><dd class="font-semibold text-gray-900">{{ $state['retreat'] }} bar{{ $team->target_pressure === null ? ' (Reserve)' : '' }}</dd></div>
                        <div><dt>Rückzug spätestens</dt><dd class="font-semibold {{ $state['minutes_to_retreat'] <= 5 ? 'text-red-700' : 'text-gray-900' }}">in ~{{ $state['minutes_to_retreat'] }} min <span class="font-normal">(geschätzt {{ $state['estimated'] }} bar)</span></dd></div>
                    </dl>
                    @if($team->target_reached_at)<p class="text-xs text-gray-500">Ziel erreicht {{ $team->target_reached_at->setTimezone('Europe/Berlin')->format('H:i') }} mit {{ $team->target_pressure }} bar</p>@endif
                    @if($team->withdrawn_at)<p class="text-xs font-semibold text-amber-800">Rückzug seit {{ $team->withdrawn_at->setTimezone('Europe/Berlin')->format('H:i') }}</p>@endif
                    @foreach($state['warnings'] as $message)<p class="rounded-lg bg-red-100 px-2 py-1 text-sm font-semibold text-red-800">{{ $message }}</p>@endforeach
                @endif

                @if($running)
                    <div class="flex flex-wrap gap-2 text-sm">
                        @if($team->status === 'ready')
                            <button type="button" wire:click="startTeam({{ $team->id }})" class="rounded-lg bg-fw-red px-4 py-2 font-semibold text-white">Anschluss – Uhr starten</button>
                            <button type="button" wire:click="removeTeam({{ $team->id }})" wire:confirm="{{ $team->name }} entfernen?" class="text-gray-500 underline">entfernen</button>
                        @else
                            <button type="button" wire:click="record({{ $team->id }}, 'check')" class="rounded-lg bg-fw-navy px-3 py-2 font-semibold text-white">Druck eintragen</button>
                            @unless($team->target_reached_at)<button type="button" wire:click="record({{ $team->id }}, 'target')" class="rounded-lg border border-gray-300 bg-white px-3 py-2 font-semibold text-gray-700">Ziel erreicht</button>@endunless
                            @unless($team->withdrawn_at)<button type="button" wire:click="withdraw({{ $team->id }})" class="rounded-lg border border-amber-400 bg-amber-50 px-3 py-2 font-semibold text-amber-900">Rückzug</button>@endunless
                            <button type="button" wire:click="record({{ $team->id }}, 'end')" wire:confirm="{{ $team->name }}: Atemschutz abgelegt? Bitte die Enddrücke eingetragen haben." class="rounded-lg border border-green-500 bg-green-50 px-3 py-2 font-semibold text-green-800">Abgelegt</button>
                        @endif
                    </div>
                @endif
            </section>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500 shadow-sm lg:col-span-2">Kein Trupp im Einsatz.</p>
        @endforelse
    </div>

    @if($running)
        <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Trupp registrieren</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <input type="text" wire:model="teamName" maxlength="100" placeholder="z. B. Angriffstrupp 1" class="{{ $input }}" aria-label="Truppname">
                <input type="text" wire:model="task" maxlength="300" placeholder="Auftrag, z. B. Menschenrettung 1. OG" class="{{ $input }}" aria-label="Auftrag">
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="csa" class="rounded"> Trupp im Chemikalienschutzanzug (CSA) – nur einsatzbereite CSA-Träger</label>
            @error('team_name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="rfid" wire:keydown.enter.prevent="addByRfid" autocomplete="off" placeholder="RFID-Chip scannen …" class="{{ $input }} sm:w-64" aria-label="RFID-Chip">
                <span class="text-xs text-gray-500">Ins Feld tippen und den Chip an den Leser halten.</span>
            </div>
            @error('rfid')<p class="text-sm text-red-600">{{ $message }}</p>@enderror

            @if($draft)
                <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200 text-sm">
                    @foreach($draft as $i => $m)
                        <li class="space-y-1 px-3 py-2" wire:key="d-{{ $i }}-{{ $m['user_id'] ?? $m['name'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-medium">{{ $m['name'] }}</span>
                                <span class="flex items-center gap-2">
                                    <label class="flex items-center gap-1 text-xs"><input type="radio" name="leader" wire:click="setLeader({{ $i }})" @checked($m['is_leader'])> TF</label>
                                    <input type="number" inputmode="numeric" min="0" max="330" wire:model="draft.{{ $i }}.start_pressure" class="w-20 rounded-lg border border-gray-300 px-2 py-1 text-sm" aria-label="Flaschendruck {{ $m['name'] }}"> bar
                                    <button type="button" wire:click="removeMember({{ $i }})" class="text-xs text-gray-500 underline">entfernen</button>
                                </span>
                            </div>
                            @if($m['reason'])
                                <label class="flex items-start gap-2 text-xs text-red-800"><input type="checkbox" wire:model="draft.{{ $i }}.override" class="mt-0.5 rounded"> Nicht einsatzbereit: {{ $m['reason'] }}. Trotzdem einsetzen (wird protokolliert).</label>
                            @endif
                            @error('members.'.$i)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ul>
            @endif
            @error('members')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" wire:model="guestName" wire:keydown.enter.prevent="addGuest" maxlength="150" placeholder="AGT einer anderen Wehr (Name)" class="{{ $input }} sm:w-64">
                <button type="button" wire:click="addGuest" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Hinzufügen</button>
            </div>
            <button type="button" wire:click="register" @disabled(count($draft) < 2) class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Trupp registrieren</button>

            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Atemschutz-Pool</p>
                <div class="flex flex-wrap gap-2">
                    @forelse($pool as $s)
                        <button type="button" wire:click="addMember({{ $s['wearer']->user_id }})" wire:key="p-{{ $s['wearer']->user_id }}"
                                class="rounded-full border px-3 py-1 text-sm {{ $s['ready'] ? ($s['soon'] ? 'border-amber-300 bg-amber-50 text-amber-900' : 'border-green-300 bg-green-50 text-green-900') : 'border-red-200 bg-red-50 text-red-800' }}"
                                title="{{ $s['ready'] ? 'einsatzbereit' : implode(', ', $s['reasons']) }}">{{ $s['wearer']->user->name }}</button>
                    @empty
                        <p class="text-sm text-gray-500">Niemand im Pool verfügbar.</p>
                    @endforelse
                </div>
            </div>
        </section>
    @endif

    @if($teams->where('status', 'done')->isNotEmpty())
        <section class="space-y-2">
            <p class="text-sm font-semibold text-gray-900">Abgelegt</p>
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @foreach($teams->where('status', 'done') as $team)
                    <div class="p-3 text-sm" wire:key="done-{{ $team->id }}">
                        <p class="font-medium text-gray-900">{{ $team->name }} · {{ $team->started_at->setTimezone('Europe/Berlin')->format('H:i') }}–{{ $team->ended_at->setTimezone('Europe/Berlin')->format('H:i') }} ({{ $team->minutes() }} min)</p>
                        <p class="text-xs text-gray-500">{{ $team->members->map(fn ($m) => $m->name.' '.$m->start_pressure.'→'.$m->end_pressure.' bar')->implode(' · ') }}@if($team->warnings->isNotEmpty()) · {{ $team->warnings->count() }} Warnung{{ $team->warnings->count() === 1 ? '' : 'en' }}@endif</p>
                    </div>
                @endforeach
            </div>
        </section>
    @endif
</div>
