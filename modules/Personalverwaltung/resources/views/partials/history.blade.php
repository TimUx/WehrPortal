{{-- The Diensthistorie grouped by kind; $edit: the Personalverwaltung corrects it and attaches Nachweise; $fileRoute: download route --}}
@php($edit = $edit ?? false)
@foreach(\Modules\Personalverwaltung\src\Models\HistoryEntry::HEADINGS as $kind => $heading)
    @if(isset($history[$kind]))
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $heading }}</p>
            <ul class="mt-1 divide-y divide-gray-100">
                @foreach($history[$kind] as $entry)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-1.5 text-sm" wire:key="h-{{ $entry->id }}">
                        @if($edit && $editing === $entry->id)
                            <div class="w-full space-y-2">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                                    <input type="text" wire:model="edit.label" @disabled($entry->isCurrent()) class="rounded-lg border border-gray-300 px-2 py-1 text-sm sm:col-span-2 disabled:bg-gray-50">
                                    <input type="date" wire:model="edit.since" class="rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                    <input type="date" wire:model="edit.until" @disabled($entry->isCurrent() || $entry->kind === 'event') class="rounded-lg border border-gray-300 px-2 py-1 text-sm disabled:bg-gray-50">
                                </div>
                                <input type="text" wire:model="edit.note" maxlength="300" placeholder="Bemerkung" class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                <div class="flex gap-3"><button type="button" wire:click="saveEdit" class="text-fw-navy underline">Speichern</button><button type="button" wire:click="$set('editing', null)" class="text-gray-500 underline">Abbrechen</button></div>
                            </div>
                        @else
                            <span class="min-w-0"><span class="{{ $entry->isCurrent() ? 'font-semibold text-gray-900' : 'text-gray-700' }}">{{ $entry->label }}</span>
                                @if($entry->note)<span class="block text-xs text-gray-500">{{ $entry->note }}</span>@endif
                                @foreach($entry->files as $file)
                                    <span class="mt-0.5 flex items-center gap-2 text-xs"><a href="{{ route('personalverwaltung.history-file', $file) }}" class="text-fw-navy underline">📎 {{ $file->filename }}</a>
                                        @if($edit)<button type="button" wire:click="deleteFile({{ $file->id }})" wire:confirm="Nachweis löschen?" class="text-red-600 underline">entfernen</button>@endif</span>
                                @endforeach
                                @if($edit && $attaching === $entry->id)
                                    <span class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                        <input type="file" wire:model="upload" accept="application/pdf,image/jpeg,image/png" class="text-xs">
                                        <button type="button" wire:click="saveFile" class="rounded border border-gray-300 bg-white px-2 py-0.5 font-semibold text-gray-700">Hochladen</button>
                                        <button type="button" wire:click="$set('attaching', null)" class="text-gray-500 underline">Abbrechen</button>
                                        @error('upload')<span class="block w-full text-red-600">{{ $message }}</span>@enderror
                                    </span>
                                @endif</span>
                            <span class="flex items-center gap-3 text-xs text-gray-500">{{ $entry->period() }}
                                @if($edit)
                                    <button type="button" wire:click="startEdit({{ $entry->id }})" class="text-fw-navy underline">Ändern</button>
                                    <button type="button" wire:click="$set('attaching', {{ $entry->id }})" class="text-fw-navy underline">Nachweis</button>
                                    @unless($entry->isCurrent())<button type="button" wire:click="deleteStage({{ $entry->id }})" wire:confirm="Diese Station löschen?" class="text-red-600 underline">Löschen</button>@endunless
                                @endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endforeach
@if($history->isEmpty())<p class="text-sm text-gray-500">Noch keine Laufbahn erfasst.</p>@endif
