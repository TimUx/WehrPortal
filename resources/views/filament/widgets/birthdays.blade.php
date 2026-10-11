<x-filament-widgets::widget>
    <x-filament::section heading="Nächste Geburtstage" icon="heroicon-o-cake">
        @if($birthdays->isEmpty())
            <p style="font-size:.875rem;color:#6b7280">Bei keinem aktiven Kameraden ist ein Geburtsdatum eingetragen.</p>
        @else
            <ul style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
                @foreach($birthdays as $b)
                    <li style="display:flex;justify-content:space-between;gap:1rem">
                        <span><strong>{{ $b['user']->name }}</strong> wird {{ $b['age'] }}</span>
                        <span style="color:{{ $b['days'] === 0 ? '#b91c1c' : '#6b7280' }};white-space:nowrap">
                            {{ $b['days'] === 0 ? 'heute' : ($b['days'] === 1 ? 'morgen' : $b['date']->format('d.m.').' (in '.$b['days'].' Tagen)') }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
