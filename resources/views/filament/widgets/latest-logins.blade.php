<x-filament-widgets::widget>
    <x-filament::section heading="Letzte Anmeldungen" icon="heroicon-o-arrow-right-on-rectangle">
        <x-slot name="afterHeader"><a href="{{ $url }}" style="font-size:.875rem;text-decoration:underline">Alle Logs</a></x-slot>
        @if($logins->isEmpty())
            <p style="font-size:.875rem;color:#6b7280">Noch keine Anmeldungen.</p>
        @else
            <ul style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
                @foreach($logins as $login)
                    <li style="display:flex;justify-content:space-between;gap:1rem">
                        <strong>{{ $login->user?->name ?? $login->subject_label }}</strong>
                        <span style="color:#6b7280;white-space:nowrap">{{ $login->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
