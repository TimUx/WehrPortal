@php
    $colors = ['ok' => '#15803d', 'warning' => '#b45309', 'error' => '#b91c1c', 'off' => '#6b7280'];
    $labels = ['ok' => 'OK', 'warning' => 'Achtung', 'error' => 'Fehler', 'off' => 'Nicht eingerichtet'];
@endphp
<x-filament-widgets::widget>
    <x-filament::section heading="Verbindungen" icon="heroicon-o-signal">
        <x-slot name="afterHeader">
            <span style="font-size:.75rem;color:#6b7280">Stand {{ \Illuminate\Support\Carbon::parse($status['checked_at'])->setTimezone('Europe/Berlin')->format('H:i') }}</span>
            <x-filament::button size="sm" color="gray" wire:click="recheck" wire:loading.attr="disabled">Jetzt prüfen</x-filament::button>
        </x-slot>
        {{-- Phone: the status as a full-width bar, the service on the line below; desktop side by side --}}
        <style>
            .ow-check { display:flex; gap:.75rem; align-items:flex-start }
            .ow-check-badge { flex-shrink:0; min-width:8.5rem }
            @media (max-width: 639px) {
                .ow-check { flex-direction:column; gap:.25rem }
                .ow-check-badge { width:100%; min-width:0 }
            }
        </style>
        <ul style="display:flex;flex-direction:column;gap:.625rem;font-size:.875rem">
            @foreach($status['checks'] as $check)
                <li class="ow-check">
                    <span class="ow-check-badge" style="border-radius:9999px;padding:.125rem .5rem;text-align:center;font-size:.75rem;font-weight:600;color:#fff;background:{{ $colors[$check['status']] }}">{{ $labels[$check['status']] }}</span>
                    <span><strong>{{ $check['label'] }}</strong><br><span style="color:#4b5563">{{ $check['message'] }}</span></span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
