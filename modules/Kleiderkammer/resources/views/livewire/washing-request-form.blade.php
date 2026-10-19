@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mb-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" x-data="{ open: @js($errors->any()) }">
    @if($flash)<p class="mb-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex w-full items-center justify-between text-left">
        <span><span class="block font-semibold text-gray-900">Wäsche anfordern</span>
            <span class="block text-sm text-gray-500">Teile wählen, Grund angeben – der Kleiderwart bekommt Bescheid.</span></span>
        <span class="text-sm text-fw-navy underline" x-text="open ? 'Schließen' : 'Anfordern'"></span>
    </button>
    <form wire:submit="send" x-show="open" x-cloak class="mt-3 space-y-3">
        <fieldset class="space-y-1">
            <legend class="text-sm font-medium text-gray-700">Welche Teile?</legend>
            @foreach($issued as $i)
                @php($type = $i->item?->itemType ?? $i->itemType)
                @php($size = $i->item?->size ?? $i->size)
                @php($isWaiting = in_array($i->id, $waiting, true))
                <label class="flex items-center gap-2 text-sm {{ $isWaiting ? 'text-gray-400' : 'text-gray-800' }}" wire:key="w-{{ $i->id }}">
                    <input type="checkbox" wire:model="items" value="{{ $i->id }}" @disabled($isWaiting) class="rounded border-gray-300 text-fw-red focus:ring-fw-red">
                    {{ $type?->name ?? 'Artikel' }}{{ $size ? ' · Größe '.$size->name : '' }}@if($i->item) <span class="font-mono text-xs text-gray-500">{{ $i->item->code }}</span>@endif
                    @if($isWaiting)<span class="text-xs">(Wäsche schon angefordert)</span>@endif
                </label>
            @endforeach
        </fieldset>
        @error('items')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <div>
            <label class="block text-sm font-medium text-gray-700" for="washing-reason">Begründung (Pflicht)</label>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach(\Modules\Kleiderkammer\src\Livewire\WashingRequestForm::REASONS as $r)
                    <button type="button" wire:click="$set('reason', @js($r))" class="rounded-full border border-gray-300 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50">{{ $r }}</button>
                @endforeach
            </div>
            <textarea id="washing-reason" wire:model="reason" rows="2" maxlength="2000" placeholder="z. B. Brandeinsatz am Samstag, Jacke und Hose verrußt" class="{{ $input }} mt-2"></textarea>
            @error('reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Wäsche anfordern</button>
    </form>
</div>
