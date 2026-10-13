@php($input = 'mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2.5 text-base focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-lg space-y-4">
    @inject('org', \App\Services\OrganizationSettings::class)
    <div>
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $org->name() }}</p>
        <h1 class="text-xl font-bold text-gray-900">Meldungen an die Einsatzleitung</h1>
        @if($valid && $alarmDate)<p class="text-sm text-gray-500">Einsatz vom {{ $alarmDate }}</p>@endif
    </div>

    @if(! $valid)
        <p class="rounded-xl bg-gray-100 p-4 text-sm text-gray-700">Dieser Zugang ist gesperrt oder der Einsatz ist beendet. Bitte direkt an die Einsatzleitung wenden.</p>
    @else
        @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
        @error('message')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

        @if(! $unit)
            {{-- First scan: who are you --}}
            <form wire:submit="register" class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm space-y-3">
                <p class="text-sm text-gray-600">Einmalig anmelden – dann siehst du hier die Statusknöpfe und kannst Lagemeldungen mit Foto schicken.</p>
                <label class="block text-sm font-medium text-gray-700">Name der Wehr<input type="text" wire:model="name" maxlength="150" required placeholder="z. B. FF Nachbarstadt" class="{{ $input }}"></label>
                @error('name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-medium text-gray-700">Fahrzeug<input type="text" wire:model="vehicle" maxlength="100" placeholder="z. B. LF 20" class="{{ $input }}"></label>
                    <label class="block text-sm font-medium text-gray-700">Stärke<input type="text" wire:model="strength" maxlength="20" placeholder="z. B. 1/8/9" class="{{ $input }}"></label>
                </div>
                @error('strength')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="submit" class="w-full rounded-lg bg-fw-red px-4 py-3 text-base font-semibold text-white">Anmelden</button>
            </form>
        @else
            <p class="rounded-xl bg-white p-3 text-sm text-gray-700 shadow-sm">Angemeldet als <strong>{{ $unit->label() }}</strong>@if($statuses->first()) · zuletzt: {{ $statuses->first()->label() }} ({{ $statuses->first()->created_at->setTimezone('Europe/Berlin')->format('H:i') }})@endif</p>

            {{-- Status --}}
            <section class="grid grid-cols-1 gap-2">
                @foreach(\Modules\Einsatzbericht\src\Models\ExternalUnit::STATUSES as $value => $label)
                    <button type="button" wire:click="sendStatus('{{ $value }}')" wire:loading.attr="disabled"
                            class="rounded-xl px-4 py-3.5 text-left text-base font-semibold {{ $statuses->first()?->status === $value ? 'bg-fw-navy text-white' : 'bg-white text-gray-900 border border-gray-200' }}">{{ $label }}</button>
                @endforeach
            </section>

            {{-- Lagemeldung --}}
            <form wire:submit="sendMessage" class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-900">Lagemeldung aus dem eigenen Abschnitt</p>
                <textarea wire:model="text" rows="3" maxlength="2000" placeholder="Lage kurz beschreiben …" class="{{ $input }}"></textarea>
                @if($photos)<p class="text-sm text-gray-600">{{ count($photos) }} {{ count($photos) === 1 ? 'Foto' : 'Fotos' }} ausgewählt</p>@endif
                @error('photos')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @error('photos.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="flex items-center gap-3">
                    <label class="inline-flex cursor-pointer items-center rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700">Foto<input type="file" wire:model="photos" accept="image/*" capture="environment" multiple class="sr-only"></label>
                    <button type="submit" wire:loading.attr="disabled" class="ml-auto rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">Senden</button>
                </div>
                <p class="text-xs text-gray-500">Fotos sollen die Lage zeigen, keine erkennbaren Personen.</p>
            </form>

            @if($entries->isNotEmpty())
                <section>
                    <p class="mb-2 text-sm font-semibold text-gray-700">Deine Meldungen</p>
                    <ul class="space-y-2">
                        @foreach($entries as $entry)
                            @if($entry instanceof \Modules\Einsatzbericht\src\Models\ExternalStatus)
                                <li class="flex items-center gap-2 rounded-xl bg-white px-3 py-2 text-sm shadow-sm"><span class="text-xs text-gray-500">{{ $entry->created_at->setTimezone('Europe/Berlin')->format('H:i') }}</span><span class="rounded-full bg-fw-navy/10 px-2 py-0.5 text-xs font-semibold text-fw-navy">Status</span><span class="font-medium text-gray-900">{{ $entry->label() }}</span></li>
                            @else
                                <li class="rounded-xl bg-white p-3 text-sm shadow-sm"><span class="text-xs text-gray-500">{{ $entry->created_at->setTimezone('Europe/Berlin')->format('H:i') }}{{ $entry->photos->isNotEmpty() ? ' · '.$entry->photos->count().' Foto(s)' : '' }}</span>@if($entry->text)<p class="whitespace-pre-line text-gray-900">{{ $entry->text }}</p>@endif</li>
                            @endif
                        @endforeach
                    </ul>
                </section>
            @endif
        @endif
    @endif
</div>
