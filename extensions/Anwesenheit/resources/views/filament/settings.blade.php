<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div style="margin-top: 1rem;">
            <x-filament::button type="submit">Speichern</x-filament::button>
        </div>
    </form>
    <p style="font-size: 0.875rem;">Die Listen selbst öffnen Kameraden unter <a href="{{ url('/anwesenheit') }}" style="text-decoration: underline;">/anwesenheit</a>. Vorlagen (Menüpunkt „Vorlagen“) füllen das Formular nur vor.</p>
</x-filament-panels::page>
