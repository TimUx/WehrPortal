@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Prüfprotokoll {{ $protocol->code() }}</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $protocol->title }}</h1>
            <p class="text-sm text-gray-600">
                @if($protocol->equipment_id)<a href="{{ route('wartung.device', $protocol->equipment_id) }}" class="underline">{{ $protocol->equipment_code }} – {{ $protocol->equipment_name }}</a>
                @else{{ $protocol->equipment_code }} – {{ $protocol->equipment_name }} (Gerät gelöscht)@endif
            </p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs font-semibold">
            @if($protocol->isVoided())<span class="rounded-full bg-gray-200 px-2.5 py-1 text-gray-700">Storniert</span>@endif
            <span class="rounded-full px-2.5 py-1 {{ $protocol->passed() ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ \Modules\Wartung\src\Models\Protocol::RESULTS[$protocol->result] }}</span>
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($protocol->isVoided())
        <p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700">Storniert von {{ $protocol->voider?->name ?? '–' }} am {{ $protocol->voided_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}: „{{ $protocol->void_reason }}“</p>
    @endif
    @unless($intact)
        <p class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">Achtung: Das Protokoll stimmt nicht mehr mit dem unterschriebenen Stand überein. Bitte einen Admin informieren.</p>
    @endunless

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[10rem_1fr]">
            <dt class="text-gray-500">Datum</dt><dd>{{ $protocol->performed_on->format('d.m.Y') }}</dd>
            <dt class="text-gray-500">Prüfer</dt><dd>{{ $protocol->performerLabel() }}</dd>
            @if($protocol->qualification)<dt class="text-gray-500">Qualifikation</dt><dd>{{ $protocol->qualification }}</dd>@endif
            @if($protocol->inspector_number)<dt class="text-gray-500">Prüfer-ID</dt><dd>{{ $protocol->inspector_number }}</dd>@endif
            <dt class="text-gray-500">Zeitaufwand</dt><dd>{{ $protocol->minutes }} Minuten</dd>
            <dt class="text-gray-500">Kosten</dt>
            <dd>{{ $money($protocol->totalCents()) }}<span class="block text-xs text-gray-500">Arbeit {{ $money($protocol->labor_cost_cents) }} ({{ $money($protocol->hourly_rate_cents) }}/h) · Material {{ $money($protocol->material_cost_cents) }}@if($protocol->external_cost_cents) · Fachfirma {{ $money($protocol->external_cost_cents) }}@endif</span></dd>
            @if($protocol->findings)<dt class="text-gray-500">Befund</dt><dd class="whitespace-pre-line">{{ $protocol->findings }}</dd>@endif
            <dt class="text-gray-500">Erfasst</dt><dd>{{ $protocol->recorder?->name ?? '–' }}, {{ $protocol->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd>
        </dl>
    </section>

    @if($protocol->checklist)
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="mb-2 text-sm font-semibold text-gray-900">Checkliste</p>
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($protocol->checklist as $row)
                    <li class="flex items-start justify-between gap-3 py-1.5">
                        <span>{{ $row['item'] }}@if($row['note'])<span class="block text-xs text-gray-600">{{ $row['note'] }}</span>@endif</span>
                        <span class="shrink-0 text-xs font-semibold {{ $row['result'] === 'defect' ? 'text-red-700' : ($row['result'] === 'ok' ? 'text-green-700' : 'text-gray-500') }}">{{ \Modules\Wartung\src\Models\Protocol::ITEM_RESULTS[$row['result']] ?? $row['result'] }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($attachments->isNotEmpty())
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="mb-2 text-sm font-semibold text-gray-900">Fotos und Dokumente</p>
            <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach($attachments as $file)
                    <li wire:key="a-{{ $file->id }}">
                        <a href="{{ route('wartung.attachment', $file) }}" target="_blank" class="block overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                            @if($file->isImage())<img src="{{ route('wartung.attachment', $file) }}" alt="{{ $file->name }}" class="h-36 w-full object-cover" loading="lazy">
                            @else<span class="flex h-36 items-center justify-center text-sm font-semibold text-gray-600">PDF</span>@endif
                        </a>
                        <p class="truncate text-xs text-gray-500">{{ $file->name }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="mb-2 text-sm font-semibold text-gray-900">Unterschrift</p>
        <img src="{{ $protocol->signatureDataUrl() }}" alt="Unterschrift {{ $protocol->inspector_name }}" class="h-24 rounded-lg border border-gray-200 object-contain">
        <p class="mt-1 text-xs text-gray-500">{{ $protocol->inspector_name }}, {{ $protocol->signed_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} Uhr</p>
        <p class="mt-2 text-xs {{ $intact ? 'text-green-700' : 'text-red-700' }}">{{ $intact ? 'Geprüft: Inhalt, Unterschrift und Anlagen sind unverändert.' : 'Prüfung fehlgeschlagen.' }}<span class="block break-all text-gray-400">Prüfsumme {{ $protocol->content_hash }}</span></p>
    </section>

    <div class="flex flex-wrap items-center gap-4 text-sm">
        <a href="{{ route('wartung.protocol.pdf', $protocol) }}" target="_blank" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">PDF</a>
        <a href="{{ route('wartung.protocols') }}" class="text-gray-500 underline">Alle Protokolle</a>
    </div>

    @if($canManage && ! $protocol->isVoided())
        <details class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Protokoll stornieren</summary>
            <div class="mt-2 space-y-2">
                <p class="text-sm text-gray-600">Für falsche oder doppelte Protokolle. Es bleibt mit dem Grund sichtbar. Die Prüffrist am Gerät ändert sich dadurch nicht – bitte in der Ausrüstungsverwaltung prüfen.</p>
                <input type="text" wire:model="voidReason" maxlength="500" placeholder="Grund, z. B. falsches Gerät gewählt" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                @error('void_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @error('wartung')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="button" wire:click="void" wire:confirm="Protokoll {{ $protocol->code() }} stornieren?" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Stornieren</button>
            </div>
        </details>
    @endif
</div>
