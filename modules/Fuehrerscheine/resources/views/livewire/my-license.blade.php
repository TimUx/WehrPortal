@php($L = \Modules\Fuehrerscheine\src\Models\License::class)
@php($in = 'rounded-lg border border-gray-300 px-2 py-1 text-sm')
<div class="space-y-4">
    @if($license)
        <section class="rounded-2xl border p-4 text-sm {{ $license->usable() && ! $license->checkDue() ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }}">
            <p class="font-semibold text-gray-900">{{ $license->statusLabel() }}</p>
            @if($license->status === 'validated')<p class="text-gray-700">Geprüft am {{ $license->validated_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $license->validator ? ' von '.$license->validator->name : '' }} · nächste Prüfung {{ $license->next_check_on?->format('d.m.Y') }}</p>@endif
            @if($license->status === 'rejected')<p class="text-red-800">Zurückgewiesen: {{ $license->rejected_reason }} – bitte korrigieren und neu einreichen.</p>@endif
            @if($license->status === 'pending')<p class="text-gray-700">Eingereicht am {{ $license->submitted_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} – der Führerscheinprüfer gleicht die Fotos ab.</p>@endif
            @if($license->documentExpired())<p class="font-semibold text-red-800">Der Führerschein selbst ist abgelaufen.</p>@endif
            @if($license->validClasses())<p class="mt-1 text-gray-700">Gültig: {{ collect($license->validClasses())->map(fn ($c) => $L::FIRE[$c] ?? $c)->implode(', ') }}</p>@endif
        </section>
    @endif
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    <form wire:submit="submit" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-4">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">{{ $license ? 'Angaben und Fotos erneuern' : 'Führerschein hinterlegen' }}</h2>
            <p class="text-xs text-gray-500">Einmal einreichen statt zur Kontrolle in die Wache kommen: Klassen ankreuzen, Ablaufdaten von der Rückseite (Spalte 11) eintragen und beide Seiten fotografieren. Nach der Prüfung werden die Fotos gelöscht; gespeichert bleiben nur Klassen und Daten.</p>
        </div>
        <label class="block text-sm text-gray-700">Führerschein (Karte) gültig bis <span class="text-xs text-gray-500">(Feld 4b; leer bei alten Führerscheinen)</span>
            <input type="date" wire:model="documentValidUntil" class="{{ $in }} mt-1 block">@error('document')<span class="block text-xs text-red-600">{{ $message }}</span>@enderror</label>
        <fieldset>
            <legend class="text-sm text-gray-700">Klassen</legend>
            <div class="mt-1 grid grid-cols-1 gap-1 sm:grid-cols-2">
                @foreach([...$L::CLASSES, ...array_keys($L::FIRE)] as $class)
                    <div class="flex items-center gap-2 text-sm">
                        <label class="flex min-w-0 flex-1 items-center gap-2"><input type="checkbox" wire:model.live="classes.{{ $class }}.on" class="rounded"> {{ $L::FIRE[$class] ?? $class }}</label>
                        @if($classes[$class]['on'] ?? false)<input type="date" wire:model="classes.{{ $class }}.valid_until" title="gültig bis" aria-label="{{ $class }} gültig bis" class="{{ $in }}">@endif
                    </div>
                @endforeach
            </div>
            <p class="mt-1 text-xs text-gray-500">C1, C, D … gelten fünf Jahre (Datum Pflicht), B unbefristet. Die Feuerwehr-Fahrberechtigung (4,75 t bzw. 7,5 t) steht auf dem Berechtigungsschein der Gemeinde.</p>
            @error('classes')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </fieldset>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Foto Vorderseite<input type="file" wire:model="front" accept="image/*" capture="environment" class="mt-1 block text-sm">@error('front')<span class="block text-xs text-red-600">{{ $message }}</span>@enderror</label>
            <label class="block text-sm text-gray-700">Foto Rückseite (Klassen)<input type="file" wire:model="back" accept="image/*" capture="environment" class="mt-1 block text-sm">@error('back')<span class="block text-xs text-red-600">{{ $message }}</span>@enderror</label>
        </div>
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Zur Prüfung einreichen</button>
    </form>
    @if($license && $license->checks->isNotEmpty())
        <ul class="space-y-0.5 text-xs text-gray-500">@foreach($license->checks as $c)<li>{{ $c->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $c->result === 'validated' ? 'geprüft' : 'zurückgewiesen' }}{{ $c->checker ? ' von '.$c->checker->name : '' }}{{ $c->note ? ': '.$c->note : '' }}</li>@endforeach</ul>
    @endif
</div>
