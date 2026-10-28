@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($big = 'text-xs py-1')
<div class="space-y-4">
    <div>
        <a href="{{ route('schichtplan.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Schichtplan</a>
        <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $plan->title }}</h1>
                <p class="text-sm text-gray-500">{{ $plan->timeLabel() }}@if($plan->location) · {{ $plan->location }}@endif</p>
                @if($showSite && $plan->site)<p class="text-sm text-gray-500">Standort: {{ $plan->site->name }}</p>@endif
                <p class="text-sm {{ $open ? 'text-green-700' : 'text-gray-600' }}">
                    @if($open) Eintragen bis {{ $plan->signupDeadline()->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr{{ $plan->needs_approval ? ' · Eintragungen werden bestätigt' : '' }}{{ $plan->max_shifts ? ' · höchstens '.$plan->max_shifts.' Schicht'.($plan->max_shifts === 1 ? '' : 'en').' je Person' : '' }}
                    @else Die Eintragung ist geschlossen.@endif
                    @if($plan->audienceLabel())<span class="block text-xs text-gray-500">Für: {{ $plan->audienceLabel() }}</span>@endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="{{ route('schichtplan.show', $plan) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Gesamter Plan</a>
            </div>
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('signup')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    @if($canChoose)
        <label class="block text-sm font-semibold text-gray-900">Wer trägt sich ein?
            <select wire:model.live="personId" class="mt-1 w-full {{ $input }}">
                <option value="">– Name wählen –</option>
                @foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
        </label>
    @endif

    @if($person && $mine->isNotEmpty())
        <div class="rounded-2xl border border-red-100 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-900">{{ $person->id === auth()->id() ? 'Deine Schichten' : 'Schichten von '.$person->name }}</p>
            <ul class="mt-1 flex flex-wrap gap-2">
                @foreach($mine as $a)
                    <li class="flex items-center gap-2 rounded-lg bg-white px-2.5 py-1 text-sm text-red-900" wire:key="m-{{ $a->id }}">
                        <span><span class="font-semibold">{{ $blocks->firstWhere('id', $a->slot_id)?->label() }}</span> {{ $stations->firstWhere('id', $a->station_id)?->name }}{{ $a->isRequested() ? ' (angefragt)' : '' }}</span>
                        @if($open || $canManage)<button type="button" wire:click="withdraw({{ $a->id }})" wire:confirm="Schicht wieder austragen?" class="text-xs text-red-700 underline">austragen</button>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($requests->isNotEmpty())
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <p class="text-sm font-semibold text-amber-900">Anfragen bestätigen</p>
            <input type="text" wire:model="rejectReason" maxlength="300" placeholder="Begründung bei Ablehnung (optional)" class="w-full {{ $input }}">
            @foreach($requests as $r)
                <div class="flex flex-wrap items-center justify-between gap-2 text-sm" wire:key="r-{{ $r->id }}">
                    <span>{{ $r->user->name }} · {{ $blocks->firstWhere('id', $r->slot_id)?->label() }} · {{ $stations->firstWhere('id', $r->station_id)?->name }}</span>
                    <span class="flex gap-2">
                        <button type="button" wire:click="approve({{ $r->id }})" class="rounded-lg bg-green-600 px-3 py-1 text-xs font-semibold text-white">Bestätigen</button>
                        <button type="button" wire:click="reject({{ $r->id }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1 text-xs font-semibold text-gray-700">Ablehnen</button>
                    </span>
                </div>
            @endforeach
        </section>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                    <th class="sticky left-0 bg-white py-2 pr-3">Zeit</th>
                    @foreach($stations as $station)
                        <th class="py-2 pr-3">{{ $station->name }}<span class="block font-normal normal-case text-gray-400">{{ $station->capacity }} Platz{{ $station->capacity === 1 ? '' : 'e' }}{{ $station->lead ? ' · '.$station->lead->name : '' }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($blocks as $slot)
                    @php($past = $slot->starts_at->lte(now()))
                    @php($busy = $person && $mine->contains('slot_id', $slot->id))
                    <tr wire:key="s-{{ $slot->id }}">
                        <td class="sticky left-0 whitespace-nowrap bg-white py-2 pr-3 font-medium ">{{ $slot->label() }}</td>
                        @foreach($stations as $station)
                            @php($seat = $cells[$slot->id.'-'.$station->id] ?? collect())
                            @php($free = $station->capacity - $seat->count())
                            <td class="py-2 pr-3 align-top">
                                @foreach($seat as $a)
                                    <div class="{{ $person && $a->user_id === $person->id ? 'font-bold text-fw-red' : 'text-gray-800' }}">{{ $a->user->name }}{{ $a->isRequested() ? ' (angefragt)' : '' }}</div>
                                @endforeach
                                @if($free > 0)
                                    @if($open && ! $past && ! $busy && $person)
                                        <button type="button" wire:click="signUp({{ $slot->id }}, {{ $station->id }})" class="mt-1 w-full rounded-lg border-2 border-dashed border-green-400 bg-green-50 px-2 {{ $big }} font-semibold text-green-800 hover:bg-green-100">
                                            Eintragen{{ $free > 1 ? ' ('.$free.' frei)' : '' }}
                                        </button>
                                    @else
                                        <div class="text-xs text-gray-400">{{ $free }} frei</div>
                                    @endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
