<div class="flex items-center gap-4">
    <x-avatar :user="$user" size="h-20 w-20" text="text-2xl" />
    <div class="space-y-1.5">
        <label class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span wire:loading.remove wire:target="photo">{{ $user->avatar_updated_at ? 'Bild ändern' : 'Bild hochladen' }}</span>
            <span wire:loading wire:target="photo">Wird hochgeladen …</span>
            <input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp,image/gif" class="sr-only">
        </label>
        @if($user->avatar_updated_at)
        <button type="button" wire:click="remove" wire:confirm="Profilbild entfernen?" class="block text-xs text-gray-500 hover:text-fw-red">Bild entfernen</button>
        @endif
        @error('photo') <p class="text-sm text-fw-red">{{ $message }}</p> @enderror
    </div>
</div>
