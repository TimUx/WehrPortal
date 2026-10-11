<x-filament-panels::page>
    {{-- The panel has no Tailwind build of its own, so the layout uses scoped styles --}}
    <style>
        .lp-tabs { display: flex; flex-wrap: wrap; gap: .5rem; }
        .lp-tab { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .8rem; border-radius: 9999px; border: 1px solid #e5e7eb; background: #fff; font-size: .875rem; cursor: pointer; }
        .lp-tab.is-active { background: #111827; color: #fff; border-color: #111827; }
        .lp-dot { min-width: 1.25rem; padding: 0 .35rem; border-radius: 9999px; background: #ef4444; color: #fff; font-size: .7rem; text-align: center; }
        .lp-stack { display: flex; flex-direction: column; gap: 1.25rem; margin-top: 1.25rem; }
        .lp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
        .lp-table th { text-align: left; padding: 0 .75rem .5rem 0; font-size: .7rem; font-weight: 500; letter-spacing: .05em; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #e5e7eb; }
        .lp-table td { padding: .55rem .75rem .55rem 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .lp-num { text-align: right; white-space: nowrap; }
        .lp-ok { color: #15803d; font-weight: 600; }
        .lp-bad { color: #b91c1c; font-weight: 600; }
        .lp-warn { color: #b45309; }
        .lp-muted { color: #6b7280; font-size: .8rem; }
        .lp-scroll { overflow-x: auto; }
        .dark .lp-tab { background: #18181b; border-color: rgb(255 255 255 / .1); color: #e5e7eb; }
        .dark .lp-tab.is-active { background: #e5e7eb; color: #111827; }
        .dark .lp-table th { border-color: rgb(255 255 255 / .1); }
        .dark .lp-table td { border-color: rgb(255 255 255 / .05); }
        .dark .lp-ok { color: #4ade80; } .dark .lp-bad { color: #f87171; } .dark .lp-warn { color: #fbbf24; }
    </style>

    @if($locations->isEmpty())
        <x-filament::section>
            Noch keine Standorte. Lege unter „Standorte &amp; Fahrzeuge“ ein Fahrzeug mit Geräteräumen und Soll-Beladung an.
        </x-filament::section>
    @else
        <div class="lp-tabs">
            @foreach($locations as $loc)
                <button type="button" wire:click="$set('location', {{ $loc['id'] }})" class="lp-tab {{ $current?->id === $loc['id'] ? 'is-active' : '' }}">
                    {{ $loc['name'] }}
                    @if($loc['missing'] > 0)<span class="lp-dot" title="Fehlende Geräte">{{ $loc['missing'] }}</span>@endif
                </button>
            @endforeach
        </div>

        <div class="lp-stack">
            @if($current && $comparison->isEmpty())
                <x-filament::section>
                    {{ $current->name }} hat noch keine Geräteräume. Lege sie unter „Standorte &amp; Fahrzeuge“ an und trage dort die Soll-Beladung ein.
                </x-filament::section>
            @endif

            @foreach($comparison as $block)
                <x-filament::section :heading="$block['compartment']->name">
                    @if($block['rows']->isEmpty() && $block['unplanned']->isEmpty())
                        <p class="lp-muted">Keine Soll-Beladung und keine Geräte.</p>
                    @else
                        <div class="lp-scroll">
                            <table class="lp-table">
                                <thead>
                                    <tr><th>Kategorie</th><th class="lp-num">Soll</th><th class="lp-num">Ist</th><th class="lp-num">Einsatzbereit</th><th>Abweichung</th></tr>
                                </thead>
                                <tbody>
                                    @foreach($block['rows'] as $row)
                                        <tr>
                                            <td>{{ $row['category'] }}</td>
                                            <td class="lp-num">{{ $row['target'] }}</td>
                                            <td class="lp-num">{{ $row['present'] }}</td>
                                            <td class="lp-num">{{ $row['usable'] }}</td>
                                            <td>
                                                @if($row['missing'] > 0)
                                                    <span class="lp-bad">{{ $row['missing'] }} {{ $row['missing'] === 1 ? 'fehlt' : 'fehlen' }}</span>
                                                @elseif($row['surplus'] > 0)
                                                    <span class="lp-warn">{{ $row['surplus'] }} zu viel</span>
                                                @else
                                                    <span class="lp-ok">vollständig</span>
                                                @endif
                                                @foreach($row['problems'] as $problem)
                                                    <div class="lp-muted">{{ $problem }}</div>
                                                @endforeach
                                            </td>
                                        </tr>
                                    @endforeach
                                    @foreach($block['unplanned'] as $item)
                                        <tr>
                                            <td>{{ $item->category?->name ?? 'Ohne Kategorie' }}</td>
                                            <td class="lp-num">0</td>
                                            <td class="lp-num">1</td>
                                            <td class="lp-num">{{ $item->isUsable() ? 1 : 0 }}</td>
                                            <td><span class="lp-warn">nicht im Plan:</span> <span class="lp-muted">{{ $item->code }} {{ $item->name }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-filament::section>
            @endforeach

            @if($unassigned->isNotEmpty())
                <x-filament::section heading="Ohne Geräteraum">
                    @foreach($unassigned as $item)
                        <div class="lp-muted">{{ $item->code }} – {{ $item->name }}</div>
                    @endforeach
                </x-filament::section>
            @endif
        </div>
    @endif
</x-filament-panels::page>
