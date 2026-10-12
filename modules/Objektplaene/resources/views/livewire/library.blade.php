@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Objektpläne & Brandschutzunterlagen</h1>
            <p class="text-sm text-gray-500">Feuerwehrpläne, Objektpläne und Brandschutzunterlagen – immer die aktuelle Version.</p>
        </div>
        {{-- Offline: pages and current plan files in this browser --}}
        <div wire:ignore x-data="offlinePlans({ urls: @js($offlineUrls), worker: @js(route('objektplaene.worker')) })" class="rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm">
            <template x-if="supported">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" x-on:click="save" x-bind:disabled="busy" class="rounded-lg bg-fw-navy px-3 py-1.5 font-semibold text-white disabled:opacity-50">
                            <span x-show="!busy">Für offline speichern</span><span x-show="busy" x-text="'Speichere … ' + done + ' / ' + total"></span>
                        </button>
                        <button type="button" x-show="savedAt" x-on:click="clear" class="text-xs text-gray-500 underline">Offline-Daten löschen</button>
                    </div>
                    <p class="text-xs text-gray-500" x-show="savedAt" x-text="'Offline gespeichert: ' + savedAt + (failed ? ' (' + failed + ' nicht geladen)' : '')"></p>
                </div>
            </template>
            <p x-show="!supported" class="text-xs text-gray-500">Offline-Speichern geht in diesem Browser nicht.</p>
        </div>
    </div>

    @foreach($running as $row)
        <section class="space-y-2 rounded-2xl border-2 border-fw-red bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-fw-red">Laufender Einsatz {{ $row['report']->keyword ?: $row['report']->number }}@if($row['report']->address) · {{ $row['report']->address }}@endif</p>
            @foreach($row['objects'] as $hit)
                @include('objektplaene::partials.object-card', ['object' => $hit['object'], 'meters' => $hit['meters']])
            @endforeach
        </section>
    @endforeach

    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Objekt oder Adresse suchen" class="min-w-0 flex-1 {{ $input }}">
        <select wire:model.live="type" class="{{ $input }}"><option value="">Alle Objekttypen</option>@foreach(\Modules\Objektplaene\src\Models\PlanObject::TYPES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
        <select wire:model.live="hazard" class="{{ $input }}"><option value="">Alle Gefährdungsklassen</option>@foreach(\Modules\Objektplaene\src\Models\PlanObject::HAZARD_CLASSES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select>
        @if($manage)<label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="due" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Prüfung fällig</label>@endif
    </div>

    <div wire:ignore x-data="waterMap({ markers: @js($markers), center: null, einsatz: null, highlight: [] })" class="space-y-2">
        <div x-ref="map" class="h-72 w-full overflow-hidden rounded-2xl border border-gray-200"></div>
        <button type="button" x-on:click="locate" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">
            <span x-show="!locating">Mein Standort – Objekte in der Nähe</span><span x-show="locating">Standort wird bestimmt …</span>
        </button>
    </div>

    @if($nearby->isNotEmpty())
        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">In der Nähe deines Standorts</p>
            @foreach($nearby as $row)
                @include('objektplaene::partials.object-card', ['object' => $row['object'], 'meters' => $row['meters']])
            @endforeach
        </section>
    @endif

    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($objects as $object)
            <a href="{{ route('objektplaene.object', $object) }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-gray-50" wire:key="o-{{ $object->id }}">
                <span class="min-w-0">
                    <span class="block font-medium text-gray-900">{{ $object->name }}</span>
                    <span class="block text-xs text-gray-500">{{ $object->typeLabel() }}@if($object->address) · {{ $object->address }}@endif · {{ $object->documents_count }} Unterlage{{ $object->documents_count === 1 ? '' : 'n' }}</span>
                </span>
                <span class="flex flex-wrap items-center gap-2">
                    @if($manage && $object->reviewDue())<span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Prüfung fällig</span>@endif
                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $object->hazardBadge() }}">{{ $object->hazardLabel() }}</span>
                </span>
            </a>
        @empty
            <p class="px-4 py-6 text-center text-sm text-gray-500">{{ trim($search) !== '' || $type || $hazard || $due ? 'Kein Objekt passt zu der Suche.' : 'Noch keine Objekte angelegt.' }}</p>
        @endforelse
    </div>
</div>
