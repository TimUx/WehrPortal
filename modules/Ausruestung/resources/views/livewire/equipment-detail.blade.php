<div class="max-w-3xl mx-auto">
    <a href="{{ route('ausruestung.equipment') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Alle Geräte</a>

    @if($flash)
        <p class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>
    @endif

    <div class="mt-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $equipment->name }}</h1>
                <p class="text-sm text-gray-500 font-mono">{{ $equipment->code }}</p>
            </div>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $equipment->status->badgeClasses() }}">{{ $equipment->status->label() }}</span>
                @foreach($equipment->warnings() as $warning)
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800">{{ $warning }}</span>
                @endforeach
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-gray-500">Kategorie</dt><dd class="text-gray-900">{{ $equipment->category?->name ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">Standort</dt><dd class="text-gray-900">{{ $equipment->placeLabel() }}</dd></div>
            <div><dt class="text-gray-500">Hersteller / Modell</dt><dd class="text-gray-900">{{ collect([$equipment->manufacturer, $equipment->model])->filter()->implode(' · ') ?: '–' }}</dd></div>
            <div><dt class="text-gray-500">Seriennummer</dt><dd class="text-gray-900">{{ $equipment->serial_number ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">Nächste Prüfung</dt><dd class="{{ $equipment->inspectionOverdue() ? 'text-red-700 font-semibold' : 'text-gray-900' }}">{{ $equipment->next_inspection_at?->format('d.m.Y') ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">Lebensdauer bis</dt><dd class="{{ $equipment->lifetimeExpired() ? 'text-red-700 font-semibold' : 'text-gray-900' }}">{{ $equipment->lifetimeEndsAt()?->format('d.m.Y') ?? '–' }}</dd></div>
            @if($equipment->last_seen_at)<div><dt class="text-gray-500">Zuletzt bei Inventur</dt><dd class="text-gray-900">{{ $equipment->last_seen_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</dd></div>@endif
            @if($costs)
                <div class="sm:col-span-2"><dt class="text-gray-500">Kosten über die Lebensdauer</dt>
                    <dd class="text-gray-900">{{ number_format($costs['total'], 2, ',', '.') }} € <span class="text-xs text-gray-500">(Kauf {{ number_format($costs['purchase'], 2, ',', '.') }} € · Reparaturen {{ number_format($costs['repairs'], 2, ',', '.') }} € · Reinigung/Wartung {{ number_format($costs['work'], 2, ',', '.') }} €)</span></dd></div>
            @endif
        </dl>

        @if(auth()->user()->canManageAusruestung())
            <a href="{{ \Modules\Ausruestung\src\Filament\Resources\EquipmentResource::getUrl('edit', ['record' => $equipment], panel: 'ausruestung') }}"
               class="mt-4 inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">In der Verwaltung öffnen</a>
        @endif
    </div>

    @if($upcoming->isNotEmpty())
        <h2 class="mt-6 mb-2 text-sm font-semibold text-gray-700">Ausgeliehen und reserviert</h2>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
            @foreach($upcoming as $loan)
                <div class="p-3 flex justify-between gap-3">
                    <span>{{ $loan->user->name }}</span>
                    <span class="text-gray-500">{{ $loan->status->label() }} · {{ $loan->starts_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} – {{ $loan->due_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
        @if($equipment->status->value !== 'retired')
        <form wire:submit="reportDamage" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Schaden melden</h2>
            <textarea wire:model="damage" rows="3" maxlength="3000" placeholder="Was ist kaputt?"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red"></textarea>
            @error('damage')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <p class="text-xs text-gray-500">Das Gerät wird als defekt gekennzeichnet und der Gerätewart informiert.</p>
            @if(app(\App\Services\ModuleRegistry::class)->isActive('maengelmanagement') && auth()->user()->canUseModule('maengelmanagement'))
                <a href="{{ route('maengelmanagement.report', ['geraet' => $equipment->code]) }}" class="block text-xs text-fw-navy underline">Lieber mit Foto im Mängelmanagement melden (mit Rückfragen und Status)</a>
            @endif
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Melden</button>
        </form>
        @endif

        @if($canReserve)
        <form wire:submit="reserve" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Reservieren</h2>
            <label class="block text-xs text-gray-500">Von
                <input type="datetime-local" wire:model="reserveFrom" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
            <label class="block text-xs text-gray-500">Bis
                <input type="datetime-local" wire:model="reserveUntil" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </label>
            @error('reserveFrom')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('reserveUntil')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Reservieren</button>
        </form>
        @endif

        @if($canManage)
        <form wire:submit="logWork" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Reinigung oder Wartung</h2>
            <div class="grid grid-cols-2 gap-2">
                <select wire:model="workType" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">@foreach($workTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <input type="datetime-local" wire:model="workDate" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input type="text" wire:model="workText" maxlength="1000" placeholder="z. B. nach Brandeinsatz gewaschen" class="col-span-2 rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <input type="text" inputmode="decimal" wire:model="workCost" placeholder="Kosten in € (optional)" class="rounded-lg border border-gray-300 px-3 py-2 text-sm">
            </div>
            @error('workType')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            @error('workCost')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Eintragen</button>
        </form>

        <form wire:submit="saveTag" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2"
              x-data="{ nfc: 'NDEFReader' in window, on: false, async learn() { try { const r = new NDEFReader(); await r.scan(); this.on = true; r.onreading = (e) => { this.on = false; $wire.set('tag', e.serialNumber); $wire.saveTag(); }; } catch (e) { this.on = false; } } }">
            <h2 class="text-sm font-semibold text-gray-900">RFID-/NFC-Tag</h2>
            <input type="text" wire:model="tag" maxlength="80" placeholder="Tag scannen oder Kennung eintippen" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            @error('tag')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Speichern</button>
                <template x-if="nfc"><button type="button" x-on:click="learn()" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" x-text="on ? 'Tag ans Handy halten …' : 'Per NFC anlernen'"></button></template>
            </div>
            <p class="text-xs text-gray-500">Für Geräte ohne Platz für ein Etikett: der Tag öffnet das Gerät wie der QR-Code (Bestand, Inventur, Scan).</p>
        </form>
        @endif

        <form wire:submit="logUse" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 space-y-2 md:col-span-2">
            <h2 class="text-sm font-semibold text-gray-900">Einsatz oder Übung eintragen</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <select wire:model="useType" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                    @foreach($useTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
                <input type="datetime-local" wire:model="useDate" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
                <input type="text" wire:model="useText" maxlength="1000" placeholder="z. B. Brand Scheune" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
            </div>
            @error('useDate')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button type="submit" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Eintragen</button>
        </form>
    </div>

    <h2 class="mt-6 mb-2 text-sm font-semibold text-gray-700">Verlauf</h2>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @forelse($events as $event)
            <div class="p-3 text-sm" wire:key="ev-{{ $event->id }}">
                <p class="text-gray-900"><span class="font-semibold">{{ $event->typeLabel() }}</span>@if($event->body) – {{ $event->body }}@endif @if($canManage && $event->cost !== null)<span class="text-xs text-gray-500">· {{ number_format((float) $event->cost, 2, ',', '.') }} €</span>@endif</p>
                <p class="text-xs text-gray-500">{{ $event->occurred_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($event->user) · {{ $event->user->name }}@endif</p>
            </div>
        @empty
            <p class="p-3 text-sm text-gray-500">Noch keine Einträge.</p>
        @endforelse
    </div>
</div>
