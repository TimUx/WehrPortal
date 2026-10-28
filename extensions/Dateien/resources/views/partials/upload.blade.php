{{-- Upload through POST /dateien/hochladen: one request per file, real progress, abortable. Rights, quota and types are checked on the server. --}}
<div wire:key="upload-{{ $nodeId ? 'node-'.$nodeId : 'folder-'.($parent ?? 's'.$spaceId) }}"
     x-data="dateienUpload()"
     data-url="{{ route('dateien.upload') }}"
     data-space="{{ $spaceId }}" data-parent="{{ $parent }}" data-node="{{ $nodeId }}"
     data-expected="{{ $nodeId ? $expectedVersion : '' }}" data-max-kb="{{ $maxUploadKb }}"
     x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="drop($event)"
     :class="over ? 'border-fw-navy bg-blue-50' : 'border-gray-300'"
     class="min-w-0 space-y-2 rounded-lg border-2 border-dashed p-3 text-sm">
    <label class="block">{{ $label }}
        <input type="file" @if($multiple) multiple @endif x-ref="input" x-on:change="add($event.target.files); $event.target.value = ''"
               accept="{{ collect($allowedExtensions)->map(fn ($e) => '.'.$e)->implode(',') }}" class="mt-1 block w-full text-sm" :disabled="busy">
    </label>
    <p class="text-xs text-gray-500">{{ $multiple ? 'Dateien hierher ziehen oder auswählen; Ordner können mit ihrer Struktur abgelegt werden.' : 'Datei hierher ziehen oder auswählen.' }} Höchstens {{ number_format($maxUploadKb / 1024, 0, ',', '.') }} MB pro Datei.</p>
    <p x-show="error" x-cloak class="text-xs text-red-700" role="alert" x-text="error"></p>
    <ul class="space-y-1" x-show="items.length" x-cloak>
        <template x-for="item in items" :key="item.id">
            <li class="flex flex-wrap items-center gap-2">
                <span class="min-w-0 flex-1 break-words" x-text="item.name"></span>
                <progress max="100" :value="item.progress" class="h-2 w-24" x-show="item.state === 'running'"></progress>
                <span class="text-xs" :class="item.state === 'error' ? 'text-red-700' : 'text-gray-600'" x-text="item.message"></span>
                <button type="button" class="text-xs font-medium text-fw-navy underline" x-show="item.state === 'waiting' || item.state === 'running'" x-on:click="cancel(item)">Abbrechen</button>
            </li>
        </template>
    </ul>
</div>
