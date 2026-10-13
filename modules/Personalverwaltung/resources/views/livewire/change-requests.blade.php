<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Änderungswünsche</h1>
        <p class="text-sm text-gray-500">Was Kameraden in ihrer Personalakte als falsch oder fehlend gemeldet haben. Korrigieren in der Akte des Kameraden (bzw. unter Admin → Kameraden), dann hier mit einer kurzen Antwort abschließen – der Kamerad bekommt sie in der Glocke und per E-Mail.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @forelse($open as $r)
        <section class="space-y-2 rounded-2xl border border-amber-200 bg-white p-4 shadow-sm" wire:key="open-{{ $r->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <a href="{{ route('personalverwaltung.member', $r->user) }}" class="font-semibold text-fw-navy underline">{{ $r->user->name }}</a>
                <span class="text-xs text-gray-500">{{ $r->topicLabel() }} · {{ $r->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
            </div>
            <p class="whitespace-pre-line text-sm text-gray-800">{{ $r->message }}</p>
            <textarea wire:model="answers.{{ $r->id }}" rows="2" maxlength="2000" placeholder="Antwort an den Kameraden (bei „Nicht übernommen“ Pflicht)" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
            @error('request-'.$r->id)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="answer({{ $r->id }}, 'done')" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Erledigt</button>
                <button type="button" wire:click="answer({{ $r->id }}, 'declined')" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Nicht übernommen</button>
            </div>
        </section>
    @empty
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Keine offenen Änderungswünsche.</p>
    @endforelse
    @if($recent->isNotEmpty())
        <section>
            <h2 class="mb-2 text-sm font-semibold text-gray-700">Zuletzt beantwortet</h2>
            <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
                @foreach($recent as $r)
                    <li class="p-3"><span class="font-medium text-gray-900">{{ $r->user->name }}</span> <span class="text-gray-500">· {{ $r->topicLabel() }} · {{ $r->statusLabel() }} {{ $r->handled_at?->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $r->handler ? ' von '.$r->handler->name : '' }}</span>
                        @if($r->answer)<span class="block text-xs text-gray-600">{{ $r->answer }}</span>@endif</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
