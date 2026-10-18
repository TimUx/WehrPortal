<x-filament-panels::page>
    <p>Alle Zugriffe und Änderungen in der Dateiablage. Das Protokoll kann nicht bearbeitet werden; ältere Einträge löscht nur der nächtliche Lauf nach der eingestellten Aufbewahrung.</p>
    <div style="display: flex; flex-wrap: wrap; gap: .75rem; align-items: end;">
        <label>Ablage
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="spaceFilter">
                <option value="">Alle</option>
                @foreach ($spaces as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <label>Kamerad
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="actorFilter">
                <option value="">Alle</option>
                @foreach ($actors as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <label>Aktion
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="actionFilter">
                <option value="">Alle</option>
                @foreach ($actions as $action)<option value="{{ $action }}">{{ $action }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <label>Von <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper></label>
        <label>Bis <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper></label>
        <x-filament::button color="gray" wire:click="export">CSV exportieren</x-filament::button>
    </div>
    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
        <thead><tr style="text-align: left;"><th>Zeitpunkt</th><th>Aktion</th><th>Kamerad</th><th>Ablage</th><th>Eintrag</th><th>Details</th></tr></thead>
        <tbody>
            @forelse ($events as $event)
                <tr wire:key="audit-{{ $event->id }}" style="border-top: 1px solid rgba(127,127,127,.2); vertical-align: top;">
                    <td style="white-space: nowrap;">{{ $event->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $event->action }}</td>
                    <td>{{ $event->actor_id ? ($names[$event->actor_id] ?? '#'.$event->actor_id) : 'System' }}</td>
                    <td>{{ $event->space_id ? ($spaces[$event->space_id] ?? '#'.$event->space_id) : '' }}</td>
                    <td>{{ $event->node_id ? '#'.$event->node_id : '' }}</td>
                    <td style="word-break: break-word;">{{ $event->metadata ? json_encode($event->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">Keine Einträge.</td></tr>
            @endforelse
        </tbody>
    </table>
    {{ $events->links() }}
</x-filament-panels::page>
