<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        @if (auth()->user()->is_admin)
            <div style="margin-top: 1rem;">
                <x-filament::button type="submit">Speichern</x-filament::button>
            </div>
        @endif
    </form>
    <p style="font-size: 0.875rem;">Die Listen selbst öffnen Kameraden unter <a href="{{ url('/anwesenheit') }}" style="text-decoration: underline;">/anwesenheit</a>. Vorlagen (Menüpunkt „Vorlagen“) füllen das Formular nur vor.</p>
</x-filament-panels::page>
