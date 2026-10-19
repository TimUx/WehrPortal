@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Neue Mitglieder</h1>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($invite)
        <section class="space-y-2 rounded-2xl border border-fw-navy bg-white p-4 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Einladung für {{ $invite['name'] }}</p>
            <img src="{{ $qr }}" alt="QR-Code der Einladung" class="mx-auto h-56 w-56">
            <p class="text-xs text-gray-500">Mit dem Handy scannen – angemeldet, ohne Passwort. Gilt bis {{ $invite['until'] }} Uhr und nur einmal.</p>
            <p class="break-all text-xs text-gray-600">{{ $invite['url'] }}</p>
            <button type="button" wire:click="mailInvite" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Per E-Mail schicken</button>
        </section>
    @endif
    <form wire:submit="create" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Neues Mitglied aufnehmen</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Name<input type="text" wire:model="member.name" maxlength="150" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">E-Mail <span class="text-gray-500">(leer = ohne Login, z. B. Jugendfeuerwehr, Musikzug)</span><input type="email" wire:model="member.email" maxlength="255" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Eintritt<input type="date" wire:model="member.joined_at" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Dienstgrad (optional)<input type="text" wire:model="member.rank" maxlength="100" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Geschlecht (für die Dienstgrade)<select wire:model="member.gender" class="{{ $input }}"><option value="">–</option><option value="male">männlich</option><option value="female">weiblich</option><option value="diverse">divers</option></select></label>
        </div>
        <div class="flex flex-wrap gap-3">@foreach($groups as $id => $name)<label class="inline-flex items-center gap-1.5 text-sm text-gray-700"><input type="checkbox" value="{{ $id }}" wire:model="member.group_ids" class="rounded"> {{ $name }}</label>@endforeach</div>
        @foreach(['member_name', 'member_email', 'member_groups'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Anlegen und einladen</button>
    </form>
    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Viele auf einmal (CSV)</p>
        <p class="text-xs text-gray-500">Z. B. die bisherige Excel-Liste: Kopfzeile mit <b>Name</b> (oder Vorname/Nachname) und <b>E-Mail</b>, optional Abteilung(en) (mit Komma), Dienstgrad, Eintritt, Geschlecht – und Geburtsdatum, Mobil, Straße, PLZ, Ort, soweit diese Felder eingeschaltet sind. Neue werden angelegt, bekannte (gleiche E-Mail) ergänzt; vorher siehst du, was passiert.</p>
        <input type="file" wire:model="file" accept=".csv,text/csv,text/plain" class="text-sm">
        @error('import')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @if($rows->isNotEmpty())
            <table class="w-full text-sm"><tbody class="divide-y divide-gray-100">
                @foreach($rows as $r)<tr><td class="py-1 text-gray-500">{{ $r['line'] }}</td><td class="py-1">{{ $r['name'] }}</td><td class="py-1 text-gray-600">{{ $r['email'] }}</td><td class="py-1 text-xs {{ $r['action'] === 'error' ? 'text-red-700' : 'text-green-700' }}">{{ $r['label'] }}</td></tr>@endforeach
            </tbody></table>
            @php($take = $rows->where('action', '!=', 'error')->count())
            <button type="button" wire:click="import" @disabled(! $take) class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ $take }} übernehmen</button>
        @endif
    </section>
</div>
