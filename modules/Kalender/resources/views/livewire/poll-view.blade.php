@php($icons = ['yes' => ['✓', 'bg-green-100 text-green-800'], 'maybe' => ['(✓)', 'bg-amber-100 text-amber-900'], 'no' => ['✗', 'bg-gray-100 text-gray-500']])
<div class="space-y-4">
    <div>
        <a href="{{ route('kalender.polls') }}" class="text-sm text-gray-500 hover:text-gray-900">← Terminabfragen</a>
        <h1 class="mt-1 text-xl font-bold text-gray-900">{{ $poll->title }}</h1>
        <p class="text-sm text-gray-500">{{ $poll->typeLabel() }}{{ $poll->location ? ' · '.$poll->location : '' }}
            @if($poll->deadline) · Antworten bis {{ $poll->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr @endif
            @if($poll->isDecided()) · <a href="{{ route('kalender.show', $poll->event_id) }}" class="font-semibold text-green-700 underline">Termin festgelegt</a>
            @elseif(! $poll->isOpen()) · geschlossen @endif</p>
        @if($poll->description)<p class="mt-1 whitespace-pre-line text-sm text-gray-700">{{ $poll->description }}</p>@endif
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('poll')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500">
                    <th class="sticky left-0 bg-white py-2 pr-3"></th>
                    @foreach($results['options'] as $r)
                        <th class="px-2 py-2 text-center {{ in_array($r['option']->id, $results['best'], true) ? 'rounded-t-lg bg-green-50 text-green-900' : '' }}">{{ $r['option']->label() }}@if(in_array($r['option']->id, $results['best'], true))<span class="block text-[10px] font-bold uppercase">bester Termin</span>@endif</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @if($canVote)
                    <tr class="bg-blue-50/50">
                        <td class="sticky left-0 bg-blue-50 py-2 pr-3 font-semibold text-gray-900">Ich</td>
                        @foreach($results['options'] as $r)
                            <td class="px-2 py-2 text-center">
                                <select wire:model="answers.{{ $r['option']->id }}" class="rounded-lg border border-gray-300 px-1 py-1 text-xs" aria-label="{{ $r['option']->label() }}">
                                    <option value="">–</option>@foreach(\Modules\Kalender\src\Models\PollVote::ANSWERS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                                </select>
                            </td>
                        @endforeach
                    </tr>
                @endif
                @foreach($results['voters'] as $voter)
                    <tr wire:key="v-{{ $voter->id }}">
                        <td class="sticky left-0 whitespace-nowrap bg-white py-1.5 pr-3 {{ $voter->id === auth()->id() ? 'font-semibold' : '' }}">{{ $voter->name }}</td>
                        @foreach($results['options'] as $r)
                            @php($answer = $r['yes']->contains('id', $voter->id) ? 'yes' : ($r['maybe']->contains('id', $voter->id) ? 'maybe' : ($r['no']->contains('id', $voter->id) ? 'no' : null)))
                            <td class="px-2 py-1.5 text-center">@if($answer)<span class="inline-block min-w-8 rounded px-1.5 py-0.5 text-xs font-semibold {{ $icons[$answer][1] }}">{{ $icons[$answer][0] }}</span>@endif</td>
                        @endforeach
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <td class="sticky left-0 bg-white py-2 pr-3 text-gray-700">Ja / wenn nötig</td>
                    @foreach($results['options'] as $r)
                        <td class="px-2 py-2 text-center {{ in_array($r['option']->id, $results['best'], true) ? 'bg-green-50 text-green-900' : '' }}">{{ $r['yes']->count() }} / {{ $r['maybe']->count() }}</td>
                    @endforeach
                </tr>
                @if($manager && ! $poll->isDecided())
                    <tr>
                        <td class="sticky left-0 bg-white py-2 pr-3 text-xs text-gray-500">Festlegen</td>
                        @foreach($results['options'] as $r)
                            <td class="px-2 py-2 text-center"><button type="button" wire:click="decide({{ $r['option']->id }})" wire:confirm="Termin {{ $r['option']->label() }} festlegen?" class="rounded-lg bg-fw-navy px-2 py-1 text-xs font-semibold text-white">Diesen</button></td>
                        @endforeach
                    </tr>
                @endif
            </tbody>
        </table>
    </div>

    @if($canVote)
        <button type="button" wire:click="save" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Antworten speichern</button>
    @endif

    @if($manager)
        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 text-sm shadow-sm">
            @if($results['open']->isNotEmpty())<p class="text-gray-600">Noch nicht geantwortet ({{ $results['open']->count() }}): {{ $results['open']->pluck('name')->implode(', ') }}</p>@endif
            @unless($poll->isDecided())
                <div class="flex flex-wrap gap-4">
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="rsvp" class="rounded"> Termin mit Rückmeldung (Ja/wenn nötig werden Zusage/Vielleicht)</label>
                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="notify" class="rounded"> Zielgruppe über den Termin informieren</label>
                </div>
                <div class="flex flex-wrap gap-4">
                    @if($poll->closed_at)<button type="button" wire:click="reopen" class="text-fw-navy underline">Wieder öffnen</button>@else<button type="button" wire:click="close" class="text-gray-600 underline">Abfrage schließen</button>@endif
                    <button type="button" wire:click="delete" wire:confirm="Terminabfrage löschen?" class="text-red-600 underline">Löschen</button>
                </div>
            @endunless
        </section>
    @endif
</div>
