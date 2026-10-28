<x-filament-panels::page>
    <form wire:submit="assign" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-shield-check">Rolle zuweisen</x-filament::button>
    </form>

    <div class="mt-8">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
