<x-layouts.app title="Lehrgangsplan">
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Lehrgangsplan {{ $year ?? '' }}</h1>
                <p class="text-sm text-gray-500">{{ $year ? 'Alle bestätigten Lehrgangstage des Jahres' : 'Die kommenden bestätigten Lehrgangstage' }} mit Zeit, Ort und Teilnehmern.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm print:hidden">
                @if($year)
                    <a href="{{ route('lehrgaenge.plan', ['jahr' => $year - 1]) }}" class="text-gray-600 underline">{{ $year - 1 }}</a>
                    <a href="{{ route('lehrgaenge.plan', ['jahr' => $year + 1]) }}" class="text-gray-600 underline">{{ $year + 1 }}</a>
                    <a href="{{ route('lehrgaenge.plan') }}" class="text-gray-600 underline">Kommende</a>
                @else
                    <a href="{{ route('lehrgaenge.plan', ['jahr' => now('Europe/Berlin')->year]) }}" class="text-gray-600 underline">Ganzes Jahr {{ now('Europe/Berlin')->year }}</a>
                @endif
                <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700">Drucken</button>
            </div>
        </div>

        @forelse($months as $month => $rows)
            <section>
                <h2 class="mb-2 text-sm font-semibold text-gray-700">{{ \Illuminate\Support\Carbon::createFromFormat('!Y-m', $month)->locale('de')->translatedFormat('F Y') }}</h2>
                <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
                    @foreach($rows as $row)
                        <div class="flex flex-wrap justify-between gap-2 p-3">
                            <div>
                                <p class="font-medium text-gray-900">{{ $row['course'] }}</p>
                                <p class="text-xs text-gray-600">{{ $row['session']->label() }}</p>
                            </div>
                            <p class="text-right text-gray-700">
                                @foreach($row['people'] as $person)
                                    <a href="{{ route('lehrgaenge.member', $person) }}" class="hover:underline">{{ $person->name }}</a>{{ $loop->last ? '' : ', ' }}
                                @endforeach
                            </p>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Keine bestätigten Lehrgangstage{{ $year ? ' in '.$year : '' }}.</p>
        @endforelse
    </div>
</x-layouts.app>
