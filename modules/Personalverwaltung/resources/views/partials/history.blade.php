{{-- The career grouped by kind; $edit: the Personalverwaltung may correct dates --}}
@php($edit = $edit ?? false)
@foreach(\Modules\Personalverwaltung\src\Models\HistoryEntry::KINDS as $kind => $kindLabel)
    @if(isset($history[$kind]))
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ ['rank' => 'Dienstgrade', 'group' => 'Abteilungen', 'function' => 'Funktionen', 'status' => 'Status'][$kind] }}</p>
            <ul class="mt-1 divide-y divide-gray-100">
                @foreach($history[$kind] as $entry)
                    <li class="flex flex-wrap items-start justify-between gap-2 py-1.5 text-sm" wire:key="h-{{ $entry->id }}">
                        @if($edit && $editing === $entry->id)
                            <div class="w-full space-y-2">
                                <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                                    <input type="text" wire:model="edit.label" @disabled($entry->isCurrent()) class="rounded-lg border border-gray-300 px-2 py-1 text-sm sm:col-span-2 disabled:bg-gray-50">
                                    <input type="date" wire:model="edit.since" class="rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                    <input type="date" wire:model="edit.until" @disabled($entry->isCurrent()) class="rounded-lg border border-gray-300 px-2 py-1 text-sm disabled:bg-gray-50">
                                </div>
                                <input type="text" wire:model="edit.note" maxlength="300" placeholder="Bemerkung" class="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm">
                                <div class="flex gap-3"><button type="button" wire:click="saveEdit" class="text-fw-navy underline">Speichern</button><button type="button" wire:click="$set('editing', null)" class="text-gray-500 underline">Abbrechen</button></div>
                            </div>
                        @else
                            <span class="min-w-0"><span class="{{ $entry->isCurrent() ? 'font-semibold text-gray-900' : 'text-gray-700' }}">{{ $entry->label }}</span>
                                @if($entry->note)<span class="block text-xs text-gray-500">{{ $entry->note }}</span>@endif</span>
                            <span class="flex items-center gap-3 text-xs text-gray-500">{{ $entry->period() }}
                                @if($edit)
                                    <button type="button" wire:click="startEdit({{ $entry->id }})" class="text-fw-navy underline">Ändern</button>
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
