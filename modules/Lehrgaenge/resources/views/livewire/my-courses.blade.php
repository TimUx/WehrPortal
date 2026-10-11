@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Meine Lehrgänge</h1>
        <p class="text-sm text-gray-500">Melde dein Interesse an einem Lehrgang. Die Ausbildungsleitung meldet für dich in fox112 Bedarf an und trägt die Termine ein, sobald die Anmeldung bestätigt ist – du bekommst sie per E-Mail.</p>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('request')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <section>
        <h2 class="text-sm font-semibold text-gray-700 mb-2">Mein Interesse und meine Anmeldungen</h2>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @forelse($open as $record)
                <div class="p-4 space-y-2" wire:key="open-{{ $record->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-2 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $record->course->name }}</p>
                            <p class="text-xs text-gray-500">
                                @if($record->status === 'confirmed')
                                    Bestätigt
                                @else
                                    Interesse gemeldet am {{ $record->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} – die Anmeldung in fox112 läuft.
                                @endif
                            </p>
                        </div>
                        <span class="flex items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $record->status === 'confirmed' ? 'bg-green-100 text-green-800' : 'bg-blue-50 text-blue-700' }}">{{ $record->statusLabel() }}</span>
                            <button type="button" wire:click="startCancel({{ $record->id }})" class="text-xs text-gray-500 underline">Absagen</button>
                        </span>
                    </div>
                    @if($record->sessions->isNotEmpty())
                        <ul class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-800 space-y-0.5">
                            @foreach($record->sessions as $session)<li>{{ $session->label() }}</li>@endforeach
                        </ul>
                    @endif
                    @if($cancelRecordId === $record->id)
                        <form wire:submit="cancel" class="space-y-2">
                            <input type="text" wire:model="cancelReason" maxlength="1000" placeholder="Grund der Absage, z. B. beruflich verhindert" class="{{ $input }}">
                            @error('cancelReason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                            <div class="flex gap-3">
                                <button type="submit" class="inline-flex items-center rounded-lg bg-gray-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-gray-800">Verbindlich absagen</button>
                                <button type="button" wire:click="$set('cancelRecordId', null)" class="text-sm text-gray-500 underline">Doch nicht</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="p-4 text-sm text-gray-500">Du hast gerade für keinen Lehrgang Interesse gemeldet.</p>
            @endforelse
        </div>
    </section>

    <section>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-700">Lehrgänge des Kreises</h2>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Lehrgang suchen" class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
        </div>
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @forelse($courses as $course)
                <div class="p-3" wire:key="c-{{ $course->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-900">{{ $course->name }}</p>
                            @if($course->variants())<p class="text-xs text-gray-500">{{ implode(' · ', $course->variants()) }}</p>@endif
                        </div>
                        @if(in_array($course->id, $openIds, true))
                            <span class="text-xs text-gray-500">Interesse gemeldet</span>
                        @elseif(in_array($course->id, $doneIds, true))
                            <span class="text-xs text-green-700">absolviert</span>
                        @else
                            <button type="button" wire:click="startRequest({{ $course->id }})" class="text-xs font-semibold text-fw-navy underline">Interesse melden</button>
                        @endif
                    </div>
                    @if($requestCourseId === $course->id)
                        <form wire:submit="sendRequest" class="mt-3 space-y-2">
                            <input type="text" wire:model="requestNote" maxlength="1000" placeholder="Bemerkung (optional), z. B. lieber am Wochenende" class="{{ $input }}">
                            <div class="flex gap-3">
                                <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Interesse melden</button>
                                <button type="button" wire:click="$set('requestCourseId', null)" class="text-sm text-gray-500 underline">Abbrechen</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="p-4 text-sm text-gray-500">{{ $search !== '' ? 'Kein Lehrgang passt zur Suche.' : 'Noch keine Lehrgänge geladen.' }}</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-gray-400">Die Lehrgänge ohne festen Termin aus @if($fox = \Modules\Lehrgaenge\src\Services\CatalogImporter::url())<a href="{{ $fox }}" target="_blank" rel="noopener" class="underline">fox112</a>@else fox112 @endif (täglich aktualisiert) und die Standardliste der Ausbildungsleitung.</p>
    </section>

    @if($completed->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Absolvierte Lehrgänge</h2>
            @include('lehrgaenge::partials.completed', ['records' => $completed])
        </section>
    @endif

    @if($cancelled->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Absagen und Ablehnungen</h2>
            @include('lehrgaenge::partials.cancellations', ['records' => $cancelled, 'showName' => false])
        </section>
    @endif
</div>
