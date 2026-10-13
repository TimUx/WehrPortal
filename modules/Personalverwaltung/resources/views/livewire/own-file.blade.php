@php($card = 'space-y-3 rounded-2xl border border-gray-200 bg-white p-5')
<div class="space-y-4">
    @if(\Modules\Personalverwaltung\src\Services\PersonnelService::canManage(auth()->user()))
        <a href="{{ route('personalverwaltung.index') }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-fw-navy hover:border-fw-navy">Zur Personalverwaltung (alle Kameraden, Ehrungen, Dienstbeteiligung) →</a>
    @endif
    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Diensthistorie</h2>
        @include('personalverwaltung::partials.service-time', ['member' => auth()->user()])
        @include('personalverwaltung::partials.history', ['history' => $history])
    </section>
    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Ehrungen</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($honors as $h)
                <li class="flex justify-between gap-2 py-1.5"><span class="font-medium text-gray-900">{{ $h->title }}@if($h->awarded_by)<span class="font-normal text-gray-500"> · {{ $h->awarded_by }}</span>@endif</span><span class="text-xs text-gray-500">{{ $h->awarded_on->format('d.m.Y') }}</span></li>
            @empty
                <li class="py-1.5 text-gray-500">Noch keine Ehrungen eingetragen.</li>
            @endforelse
        </ul>
    </section>
    @if($profile?->id_card_number || $profile?->id_card_valid_until)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Dienstausweis</h2>
            <p class="text-sm text-gray-700">{{ $profile->id_card_number ? 'Nr. '.$profile->id_card_number : '' }}@if($profile->id_card_valid_until) · gültig bis {{ $profile->id_card_valid_until->format('d.m.Y') }}@endif</p>
        </section>
    @endif
    @if($attendance->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Meine Dienstbeteiligung</h2>
            @include('personalverwaltung::partials.attendance', ['attendance' => $attendance])
        </section>
    @endif
    <section class="{{ $card }}" id="aenderung">
        <h2 class="text-sm font-semibold text-gray-900">Stimmt etwas nicht?</h2>
        <p class="text-xs text-gray-500">Melde der Personalverwaltung, was in deiner Personalakte fehlt oder falsch ist – sie prüft es und gibt dir Bescheid.</p>
        @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
        <form wire:submit="requestChange" class="space-y-2">
            <select wire:model="topic" class="w-full rounded-lg border-gray-300 text-sm" aria-label="Worum geht es?">
                @foreach(\Modules\Personalverwaltung\src\Models\ChangeRequest::TOPICS as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <textarea wire:model="requestText" rows="3" maxlength="2000" placeholder="z. B. „Beförderung zum Oberfeuerwehrmann war am 12.03.2027“" class="w-full rounded-lg border-gray-300 text-sm"></textarea>
            @foreach(['request_topic', 'request_message'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
            <button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Änderung melden</button>
        </form>
        @if($requests->isNotEmpty())
            <ul class="divide-y divide-gray-100 border-t border-gray-100 text-sm">
                @foreach($requests as $r)
                    <li class="py-2" wire:key="cr-{{ $r->id }}">
                        <p class="flex flex-wrap justify-between gap-2"><span class="text-gray-900">{{ $r->topicLabel() }}</span>
                            <span class="text-xs {{ $r->status === 'open' ? 'text-amber-700' : ($r->status === 'done' ? 'text-green-700' : 'text-gray-500') }}">{{ $r->statusLabel() }} · {{ $r->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</span></p>
                        <p class="text-xs text-gray-600">{{ $r->message }}</p>
                        @if($r->answer)<p class="mt-0.5 text-xs text-gray-800">Antwort: {{ $r->answer }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
