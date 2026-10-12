@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($card = 'rounded-2xl border border-gray-100 bg-white p-4 shadow-sm')
<div class="mx-auto max-w-4xl space-y-4">
    <a href="{{ route('objektplaene.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Bibliothek</a>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('objektplaene')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section class="{{ $card }} space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-gray-900">{{ $object->name }}</h1>
                <p class="text-sm text-gray-500">{{ $object->typeLabel() }}@if($object->address) · {{ $object->address }}@endif
                    @if($object->navigationUrl()) · <a href="{{ $object->navigationUrl() }}" target="_blank" rel="noopener" class="underline">Navigation</a>@endif</p>
                @unless($object->is_active)<p class="mt-1 text-xs font-semibold text-gray-500">Archiviert – erscheint nicht in der Bibliothek und nicht im Einsatz.</p>@endunless
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="rounded px-2 py-1 text-sm font-semibold {{ $object->hazardBadge() }}" title="{{ \Modules\Objektplaene\src\Models\PlanObject::HAZARD_HINTS[$object->hazard_class] ?? '' }}">{{ $object->hazardLabel() }}</span>
                @if($manage)<a href="{{ route('objektplaene.edit', $object) }}" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Bearbeiten</a>@endif
            </div>
        </div>
        @if($object->features())<p class="text-sm font-medium text-gray-800">{{ implode(' · ', $object->features()) }}</p>@endif
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
            @foreach(['hazards' => 'Besondere Gefahren', 'access' => 'Zufahrt, Anleiterstellen, BMA/FIBS', 'contacts' => 'Ansprechpartner', 'notes' => 'Hinweise'] as $field => $label)
                @if($object->{$field})
                    <div class="{{ $field === 'hazards' ? 'rounded-lg bg-red-50 p-2' : '' }}">
                        <dt class="font-semibold {{ $field === 'hazards' ? 'text-red-900' : 'text-gray-700' }}">{{ $label }}</dt>
                        <dd class="whitespace-pre-line {{ $field === 'hazards' ? 'text-red-900' : 'text-gray-800' }}">{{ $object->{$field} }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </section>

    <section class="{{ $card }} space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-900">Unterlagen</h2>
            @if($manage && $mode === null)<button type="button" wire:click="startDocument" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Unterlage hinzufügen</button>@endif
        </div>

        @if($manage && $mode !== null)
            <form wire:submit="saveDocument" class="space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-3">
                <p class="text-sm font-semibold text-gray-900">{{ $mode === 'version' ? 'Neue Version: '.$doc['title'] : 'Neue Unterlage' }}</p>
                @if($mode === 'document')
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <label class="block text-sm text-gray-700">Art
                            <select wire:model="doc.kind" class="{{ $input }}">@foreach(\Modules\Objektplaene\src\Models\Document::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        <label class="block text-sm text-gray-700">Titel<input type="text" wire:model="doc.title" maxlength="150" placeholder="z. B. Feuerwehrplan Hauptgebäude" class="{{ $input }}"></label>
                    </div>
                    @error('kind')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    @error('title')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                @endif
                <label class="block text-sm text-gray-700">Datei (PDF oder Bild, höchstens 10 MB)
                    <input type="file" wire:model="file" accept="application/pdf,image/*" class="mt-1 block w-full text-sm">
                </label>
                <div wire:loading wire:target="file" class="text-xs text-gray-500">Datei wird hochgeladen …</div>
                @error('file')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="block text-sm text-gray-700">Stand des Plans<input type="date" wire:model="doc.valid_from" class="{{ $input }}"></label>
                    <label class="block text-sm text-gray-700 sm:col-span-2">{{ $mode === 'version' ? 'Was hat sich geändert?' : 'Hinweis (optional)' }}<input type="text" wire:model="doc.note" maxlength="500" class="{{ $input }}"></label>
                </div>
                @error('valid_from')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Speichern</button>
                    @if($mode === 'version')<button type="button" wire:click="renameDocument({{ $versionFor }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700">Nur Titel/Art speichern</button>@endif
                    <button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button>
                </div>
                @if($mode === 'version')
                    <div class="grid grid-cols-1 gap-3 border-t border-gray-200 pt-3 sm:grid-cols-2">
                        <label class="block text-xs text-gray-600">Art
                            <select wire:model="doc.kind" class="{{ $input }}">@foreach(\Modules\Objektplaene\src\Models\Document::KINDS as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
                        </label>
                        <label class="block text-xs text-gray-600">Titel<input type="text" wire:model="doc.title" maxlength="150" class="{{ $input }}"></label>
                    </div>
                @endif
            </form>
        @endif

        <div class="divide-y divide-gray-100">
            @forelse($object->documents as $document)
                <div class="space-y-2 py-3" wire:key="d-{{ $document->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $document->title }}</p>
                            <p class="text-xs text-gray-500">{{ $document->kindLabel() }}
                                @if($document->current) · Version {{ $document->current->version }}@if($document->current->valid_from), Stand {{ $document->current->valid_from->format('d.m.Y') }}@endif
                                    · hochgeladen {{ $document->current->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}@if($document->current->uploader) von {{ $document->current->uploader->name }}@endif
                                    · {{ $document->current->sizeLabel() }}@endif</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if($document->current)
                                <a href="{{ $document->current->url() }}" target="_blank" rel="noopener" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Öffnen</a>
                            @endif
                            @if($manage)
                                <button type="button" wire:click="startVersion({{ $document->id }})" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Neue Version</button>
                                <button type="button" wire:click="toggleHistory({{ $document->id }})" class="text-sm text-fw-navy underline">Verlauf</button>
                                <button type="button" wire:click="deleteDocument({{ $document->id }})" wire:confirm="„{{ $document->title }}“ mit allen Versionen löschen?" class="text-sm text-red-600 underline">Löschen</button>
                            @endif
                        </div>
                    </div>
                    @if($manage && $historyFor === $document->id && $history)
                        <ul class="space-y-1 rounded-lg bg-gray-50 p-2 text-xs text-gray-700">
                            @foreach($history as $version)
                                <li class="flex flex-wrap items-center justify-between gap-2">
                                    <span>Version {{ $version->version }}@if($version->id === $document->current?->id) <strong>(aktuell)</strong>@endif
                                        · {{ $version->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}@if($version->uploader) · {{ $version->uploader->name }}@endif
                                        @if($version->valid_from) · Stand {{ $version->valid_from->format('d.m.Y') }}@endif
                                        @if($version->note) · {{ $version->note }}@endif</span>
                                    <a href="{{ $version->url() }}" target="_blank" rel="noopener" class="text-fw-navy underline">{{ $version->name }}</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @empty
                <p class="py-3 text-sm text-gray-500">Noch keine Unterlagen hinterlegt.</p>
            @endforelse
        </div>
    </section>

    @if($object->hasPosition())
        <section class="{{ $card }}">
            <div wire:ignore x-data="waterMap({ markers: @js(\Modules\Objektplaene\src\Services\ObjectPlanService::markers(collect([$object]))), center: null, einsatz: null, highlight: [{{ $object->id }}] })">
                <div x-ref="map" class="h-56 w-full overflow-hidden rounded-xl border border-gray-200"></div>
            </div>
        </section>
    @endif

    @if($manage)
        <section class="{{ $card }} space-y-3">
            <h2 class="text-sm font-semibold text-gray-900">Überprüfung</h2>
            <p class="text-sm text-gray-700">
                @if($object->last_reviewed_on)Zuletzt geprüft am {{ $object->last_reviewed_on->format('d.m.Y') }}.@else Noch nicht geprüft.@endif
                @if($object->next_review_on) Nächste Prüfung: <span class="{{ $object->reviewDue() ? 'font-semibold text-amber-700' : '' }}">{{ $object->next_review_on->format('d.m.Y') }}</span>.@else Keine Prüffrist gesetzt.@endif
            </p>
            <div class="flex flex-wrap items-end gap-2">
                <label class="block min-w-0 flex-1 text-sm text-gray-700">Hinweis (optional)<input type="text" wire:model="reviewNote" maxlength="500" placeholder="z. B. Begehung mit dem Hausmeister, Pläne stimmen" class="{{ $input }}"></label>
                <button type="button" wire:click="review" class="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700">Unterlagen geprüft</button>
            </div>
            @if($object->reviews->isNotEmpty())
                <ul class="space-y-1 text-xs text-gray-600">
                    @foreach($object->reviews->take(10) as $review)
                        <li>{{ $review->reviewed_on->format('d.m.Y') }}@if($review->user) · {{ $review->user->name }}@endif @if($review->note) · {{ $review->note }}@endif</li>
                    @endforeach
                </ul>
            @endif
            <div class="border-t border-gray-100 pt-3">
                <button type="button" wire:click="deleteObject" wire:confirm="„{{ $object->name }}“ mit allen Unterlagen und Versionen löschen? Zum Aufbewahren lieber archivieren (Bearbeiten → nicht mehr aktiv)." class="text-sm text-red-600 underline">Objekt löschen</button>
            </div>
        </section>
    @endif
</div>
