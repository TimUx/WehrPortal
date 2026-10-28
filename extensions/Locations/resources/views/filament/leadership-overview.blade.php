<x-filament-panels::page>
    <x-filament::section
        heading="Jährliche Einsatzzahlen"
        description="Gesamtzahlen der freigegebenen Einsatzberichte je aktivem Standort. Einzelberichte und personenbezogene Inhalte sind hier nicht zugänglich."
    >
        <label>
            <span>Jahr</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="year" aria-label="Jahr">
                    @foreach ($years as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </x-filament::section>

    @forelse ($summaries as $summary)
        <x-filament::section :heading="$summary['location']">
            <dl>
                <dt>Abgeschlossene Einsätze {{ $summary['year'] }}</dt>
                <dd>{{ number_format($summary['incident_count'], 0, ',', '.') }}</dd>
            </dl>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p>Es sind keine aktiven Standorte vorhanden.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
