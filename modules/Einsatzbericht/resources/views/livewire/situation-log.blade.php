{{-- Einsatztagebuch; refreshes every 15 seconds while the Einsatz runs --}}
<div class="space-y-3" @if($running) wire:poll.15s @endif>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('message')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <form wire:submit="send" class="space-y-2 rounded-xl border border-gray-200 bg-white p-3">
        <textarea wire:model="text" rows="2" maxlength="2000" placeholder="Lage kurz beschreiben …" class="block w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red"></textarea>
        @if($photos)
            <div class="flex flex-wrap gap-2">
                @foreach($photos as $i => $photo)
                    <div class="relative" wire:key="up-{{ $i }}">
                        @if(method_exists($photo, 'isPreviewable') && $photo->isPreviewable())<img src="{{ $photo->temporaryUrl() }}" alt="" class="h-16 w-16 rounded-lg object-cover">@else<span class="flex h-16 w-16 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-500">Foto</span>@endif
                        <button type="button" wire:click="removePhoto({{ $i }})" class="absolute -right-1 -top-1 flex h-6 w-6 items-center justify-center rounded-full bg-gray-800 text-xs text-white" aria-label="Foto entfernen">×</button>
                    </div>
                @endforeach
            </div>
        @endif
        @error('photos')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        @error('photos.*')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-center gap-3">
            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-700">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.66-.9l.82-1.2A2 2 0 0110.07 4h3.86a2 2 0 011.66.9l.82 1.2a2 2 0 001.66.9H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                Foto
                <input type="file" wire:model="photos" accept="image/*" capture="environment" multiple class="sr-only">
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="important" class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> Wichtig</label>
            <button type="submit" wire:loading.attr="disabled" class="ml-auto rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                <span wire:loading.remove wire:target="send,photos">Senden</span><span wire:loading wire:target="send,photos">Moment …</span>
            </button>
        </div>
        <p class="text-xs text-gray-500">Fotos sollen die Lage zeigen, keine erkennbaren Personen. Höchstens {{ \Modules\Einsatzbericht\src\Services\SituationService::MAX_PHOTOS }} je Meldung.</p>
    </form>

    <ul class="space-y-2">
        @forelse($messages as $msg)
            <li class="rounded-xl border p-3 {{ $msg->important ? 'border-fw-red bg-red-50' : 'border-gray-200 bg-white' }}" wire:key="m-{{ $msg->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2 text-xs text-gray-500">
                    <span>
                        @if($msg->important)<span class="mr-1 rounded bg-fw-red px-1.5 py-0.5 font-bold uppercase text-white">Wichtig</span>@endif
                        {{ $msg->created_at->setTimezone('Europe/Berlin')->format('d.m. H:i') }} · @if($msg->external_unit_id)<span class="font-semibold text-blue-800">{{ $msg->authorLabel() }}</span>@else{{ $msg->authorLabel() }}@endif
                    </span>
                    <span class="flex gap-3">
                        @if($lead)<button type="button" wire:click="toggleImportant({{ $msg->id }})" class="underline">{{ $msg->important ? 'Nicht wichtig' : 'Wichtig' }}</button>@endif
                        @if($lead || ($msg->user_id === auth()->id() && $running))<button type="button" wire:click="delete({{ $msg->id }})" wire:confirm="Meldung löschen?" class="text-red-600 underline">Löschen</button>@endif
                    </span>
                </div>
                @if($msg->text)<p class="mt-1 whitespace-pre-line text-sm text-gray-900">{{ $msg->text }}</p>@endif
                @if($msg->photos->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($msg->photos as $photo)
                            <a href="{{ route('einsatzbericht.photo', $photo) }}" target="_blank" rel="noopener"><img src="{{ route('einsatzbericht.photo', $photo) }}" alt="Foto zur Lagemeldung" loading="lazy" class="h-24 w-24 rounded-lg object-cover sm:h-32 sm:w-32"></a>
                        @endforeach
                    </div>
                @endif
            </li>
        @empty
            <li class="rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">Noch keine Lagemeldungen.</li>
        @endforelse
    </ul>
</div>
