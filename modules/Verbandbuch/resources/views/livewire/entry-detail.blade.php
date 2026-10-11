@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($time = fn ($t) => $t?->setTimezone('Europe/Berlin')->format('d.m.Y, H:i').' Uhr')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">Verbandbuch · Nr. {{ $entry->code() }}</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $entry->kindLabel() }}{{ $entry->injured_name ? ' – '.$entry->injured_name : '' }}</h1>
        </div>
        <div class="flex flex-wrap gap-2 text-xs font-semibold">
            @if($entry->isVoided())<span class="rounded-full bg-gray-200 px-2.5 py-1 text-gray-700">Storniert</span>@endif
            @if($entry->needsReport())<span class="rounded-full bg-amber-100 px-2.5 py-1 text-amber-900">Unfallanzeige prüfen</span>@endif
            @if($entry->reported_at)<span class="rounded-full bg-green-100 px-2.5 py-1 text-green-800">Unfallanzeige vermerkt</span>@endif
        </div>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('entry')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @if($entry->isVoided())
        <p class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-700">Storniert von {{ $entry->voider?->name ?? '–' }} am {{ $time($entry->voided_at) }}: „{{ $entry->void_reason }}“. Der Eintrag bleibt zum Nachweis erhalten.</p>
    @endif
    @unless($intact)
        <p class="rounded-lg bg-red-50 px-3 py-2 text-sm font-semibold text-red-800">Achtung: Der Inhalt stimmt nicht mehr mit dem unterschriebenen Stand überein. Bitte einen Admin informieren.</p>
    @endunless

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
            <dt class="text-gray-500">Verletzte / beteiligte Person</dt><dd>{{ $entry->injured_name ?? '–' }}</dd>
            <dt class="text-gray-500">Zeitpunkt</dt><dd>{{ $time($entry->occurred_at) }}</dd>
            <dt class="text-gray-500">Wobei</dt><dd>{{ $entry->occasionLabel() }}</dd>
            <dt class="text-gray-500">Ort</dt><dd>{{ $entry->location }}</dd>
            <dt class="text-gray-500">Hergang</dt><dd class="whitespace-pre-line">{{ $entry->description }}</dd>
            @if($entry->injury)<dt class="text-gray-500">Art und Umfang der Verletzung</dt><dd class="whitespace-pre-line">{{ $entry->injury }}</dd>@endif
            <dt class="text-gray-500">Zeugen</dt><dd class="whitespace-pre-line">{{ $entry->witnesses ?? '–' }}</dd>
            @if($entry->first_aid_measures)
                <dt class="text-gray-500">Erste-Hilfe-Maßnahmen</dt><dd class="whitespace-pre-line">{{ $entry->first_aid_measures }}</dd>
                <dt class="text-gray-500">Erste Hilfe durch</dt><dd>{{ $entry->first_aider_name }}, {{ $time($entry->first_aid_at) }}</dd>
            @endif
            @if($entry->kind !== 'incident')<dt class="text-gray-500">Arztbesuch</dt><dd>{{ $entry->doctor_visited ? 'ja' : 'nein' }}</dd>@endif
            <dt class="text-gray-500">Erfasst</dt><dd>{{ $entry->recorded_by_name }}, {{ $time($entry->created_at) }}</dd>
            @if($entry->reported_at)
                <dt class="text-gray-500">Unfallanzeige</dt>
                <dd>{{ $entry->reported_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} ({{ $entry->reporter?->name ?? '–' }})@if($entry->report_note)<span class="block text-gray-600">{{ $entry->report_note }}</span>@endif</dd>
            @endif
        </dl>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="mb-2 text-sm font-semibold text-gray-900">Unterschriften</p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach($signatures as $signature)
                <figure class="space-y-1">
                    <img src="{{ $signature->dataUrl() }}" alt="Unterschrift {{ $signature->name }}" class="h-24 w-full rounded-lg border border-gray-200 object-contain">
                    <figcaption class="text-xs text-gray-500">{{ $signature->roleLabel() }}: {{ $signature->name }}, {{ $time($signature->signed_at) }}</figcaption>
                </figure>
            @endforeach
        </div>
        <p class="mt-3 text-xs {{ $intact ? 'text-green-700' : 'text-red-700' }}">
            {{ $intact ? 'Geprüft: Inhalt, Unterschriften und Nachträge sind unverändert.' : 'Prüfung fehlgeschlagen.' }}
            <span class="block break-all text-gray-400">Prüfsumme {{ $entry->content_hash }}</span>
        </p>
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Nachträge</p>
        @forelse($amendments as $amendment)
            <div class="border-l-2 border-gray-200 pl-3 text-sm" wire:key="a-{{ $amendment->id }}">
                <p class="text-xs text-gray-500">{{ $amendment->user_name }}, {{ $time($amendment->created_at) }}</p>
                <p class="whitespace-pre-line text-gray-800">{{ $amendment->text }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine Nachträge.</p>
        @endforelse
        @if($canAmend)
            <textarea wire:model="amendment" rows="2" maxlength="2000" placeholder="Ergänzung oder Korrektur, z. B. „Am Folgetag Arzt aufgesucht, Prellung bestätigt.“" class="{{ $input }}"></textarea>
            @error('amendment')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <button type="button" wire:click="addAmendment" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Nachtrag speichern</button>
        @endif
    </section>

    @if($canManage && ! $entry->isVoided())
        @if($entry->kind === 'accident' && ! $entry->reported_at)
            <section class="space-y-2 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-900">Unfallanzeige an die Unfallkasse</p>
                <p class="text-sm text-amber-900">Pflicht, wenn der Unfall zu mehr als drei Tagen Arbeitsunfähigkeit führt oder ärztlich behandelt werden musste. Hier vermerken, wann sie rausging (oder warum nicht nötig).</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-[10rem_1fr]">
                    <input type="date" wire:model="reportedAt" class="{{ $input }}" aria-label="Datum der Unfallanzeige">
                    <input type="text" wire:model="reportNote" maxlength="300" placeholder="z. B. per Online-Meldung, Aktenzeichen 123/27" class="{{ $input }}" aria-label="Hinweis">
                </div>
                @error('reported_at')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="button" wire:click="markReported" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Unfallanzeige vermerken</button>
            </section>
        @endif

        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Aufbewahrung</p>
            <p class="text-sm text-gray-600">
                @if($entry->hold)
                    Wird nicht automatisch gelöscht (z. B. solange die Unfallkasse noch Fragen hat).
                @else
                    Wird nach dem {{ $entry->retain_until->format('d.m.Y') }} automatisch gelöscht.
                @endif
            </p>
            <button type="button" wire:click="toggleHold" class="text-sm text-fw-navy underline">{{ $entry->hold ? 'Wieder nach Frist löschen' : 'Länger aufbewahren' }}</button>
        </section>

        <details class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Eintrag stornieren</summary>
            <div class="mt-2 space-y-2">
                <p class="text-sm text-gray-600">Für Einträge, die falsch oder doppelt sind. Der Eintrag bleibt mit dem Grund sichtbar und wird nicht gelöscht.</p>
                <input type="text" wire:model="voidReason" maxlength="500" placeholder="Grund, z. B. doppelt erfasst, siehe Nr. 2027-004" class="{{ $input }}">
                @error('void_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <button type="button" wire:click="void" wire:confirm="Eintrag {{ $entry->code() }} stornieren?" class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Stornieren</button>
            </div>
        </details>
    @endif

    <div class="flex flex-wrap items-center gap-4 text-sm">
        <a href="{{ route('verbandbuch.print', $entry) }}" target="_blank" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">Drucken / PDF</a>
        <a href="{{ route('verbandbuch.index') }}" class="text-gray-500 underline">Zurück</a>
    </div>

    @if($log->isNotEmpty())
        <section>
            <p class="mb-2 text-sm font-semibold text-gray-700">Zugriffe</p>
            <ul class="space-y-1 text-xs text-gray-600">
                @foreach($log as $row)
                    <li>{{ $row->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $row->user?->name ?? 'System' }} · {{ $row->label() }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
