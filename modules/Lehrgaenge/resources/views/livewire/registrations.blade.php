@php($input = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <h1 class="text-xl font-bold text-gray-900">Lehrgangsanmeldungen</h1>
            <p class="text-sm text-gray-500">Für Kameraden mit Interesse in fox112 Bedarf anmelden. Nach der Bestätigung die Termine eintragen – der Kamerad bekommt sie per E-Mail. Nach dem Lehrgang „Absolviert“ setzen.</p>
        </div>
        <div class="text-right">
            <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                <span wire:loading.remove wire:target="import">Lehrgänge von fox112 aktualisieren</span>
                <span wire:loading wire:target="import">Wird aktualisiert …</span>
            </button>
            @unless(\Modules\Lehrgaenge\src\Services\CatalogImporter::url())<p class="mt-1 text-xs text-amber-700">Noch keine fox112-Adresse – unter <a href="{{ route('lehrgaenge.courses') }}" class="underline">Lehrgangsliste</a> eintragen.</p>@endunless
            <p class="mt-1 text-xs {{ $lastRun['at'] && ! $lastRun['ok'] ? 'text-red-700' : 'text-gray-400' }}">
                @if($lastRun['at'])Stand {{ \Illuminate\Support\Carbon::parse($lastRun['at'])->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $lastRun['ok'] ? '' : ' – fehlgeschlagen' }}@else Noch nicht geladen @endif
            </p>
        </div>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($error)<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $error }}</p>@endif

    @if($cancelled->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-1">Absagen</h2>
            <p class="text-xs text-gray-500 mb-2">Diese Kameraden haben abgesagt – bitte in fox112 wieder abmelden. Angezeigt werden die letzten 90 Tage.</p>
            @include('lehrgaenge::partials.cancellations', ['records' => $cancelled, 'showName' => true])
        </section>
    @endif

    <section>
        <h2 class="text-sm font-semibold text-gray-700 mb-2">Interesse</h2>
        @forelse($demand as $block)
            <div class="mb-3 bg-white rounded-2xl border border-gray-100 shadow-sm" wire:key="d-{{ $block['course']->id }}">
                <p class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-900">{{ $block['course']->name }} <span class="font-normal text-gray-500">· {{ $block['records']->count() }} Kamerad{{ $block['records']->count() === 1 ? '' : 'en' }}</span></p>
                <div class="divide-y divide-gray-100">
                    @foreach($block['records'] as $position => $record)
                        <div class="p-3 space-y-2" wire:key="req-{{ $record->id }}">
                            <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                                <div class="flex gap-3">
                                    <span class="mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700" title="Reihenfolge der Anmeldung">{{ $position + 1 }}</span>
                                    <div>
                                        <a href="{{ route('lehrgaenge.member', $record->user) }}" class="font-medium text-gray-900 hover:underline">{{ $record->user->name }}</a>
                                        <p class="text-xs text-gray-500">Interesse seit {{ $record->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</p>
                                        @if($record->notes)<p class="text-xs text-gray-600 whitespace-pre-line">{{ $record->notes }}</p>@endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" wire:click="editSessions({{ $record->id }})" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Bestätigt – Termine eintragen</button>
                                    <button type="button" wire:click="startReject({{ $record->id }})" class="text-sm text-gray-500 underline">Ablehnen</button>
                                </div>
                            </div>
                            @include('lehrgaenge::partials.sessions-form')
                            @include('lehrgaenge::partials.reject-form')
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Gerade hat niemand Interesse an einem Lehrgang gemeldet.</p>
        @endforelse
    </section>

    @if($confirmed->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Bestätigt</h2>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
                @foreach($confirmed as $record)
                    <div class="p-3 space-y-2" wire:key="conf-{{ $record->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                            <div>
                                <p><a href="{{ route('lehrgaenge.member', $record->user) }}" class="font-medium text-gray-900 hover:underline">{{ $record->user->name }}</a> · {{ $record->course->name }}</p>
                                <ul class="text-xs text-gray-600">@foreach($record->sessions as $session)<li>{{ $session->label() }}</li>@endforeach</ul>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="complete({{ $record->id }})" wire:confirm="{{ $record->user->name }} hat „{{ $record->course->name }}“ erfolgreich besucht?" class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-green-700">Absolviert</button>
                                <button type="button" wire:click="editSessions({{ $record->id }})" class="text-sm text-fw-navy underline">Termine ändern</button>
                                <button type="button" wire:click="startReject({{ $record->id }})" class="text-sm text-gray-500 underline">Ablehnen</button>
                            </div>
                        </div>
                        @include('lehrgaenge::partials.sessions-form')
                        @include('lehrgaenge::partials.reject-form')
                    </div>
                @endforeach
            </div>
        </section>
    @endif
    @if($rejected->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Abgelehnt (letzte 90 Tage)</h2>
            @include('lehrgaenge::partials.cancellations', ['records' => $rejected, 'showName' => true])
        </section>
    @endif
</div>
