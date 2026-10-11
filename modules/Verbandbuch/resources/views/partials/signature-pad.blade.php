{{-- A pad to sign on; $model is the Livewire property that gets the PNG --}}
<div x-data="signaturePad(@js($model))" class="space-y-1">
    <div wire:ignore class="relative rounded-lg border-2 border-dashed border-gray-300 bg-white">
        <canvas x-ref="canvas" x-on:pointerdown="start" x-on:pointermove="move" x-on:pointerup="end" x-on:pointercancel="end"
                class="block h-40 w-full cursor-crosshair touch-none" aria-label="{{ $label }}"></canvas>
        <span x-show="!signed" class="pointer-events-none absolute inset-x-0 bottom-3 text-center text-xs text-gray-400">Hier mit dem Finger oder der Maus unterschreiben</span>
    </div>
    <div class="flex items-center justify-between text-xs text-gray-500">
        <span>{{ $label }}</span>
        <button type="button" x-on:click="clear" x-show="signed" class="underline">Löschen und neu unterschreiben</button>
    </div>
</div>
