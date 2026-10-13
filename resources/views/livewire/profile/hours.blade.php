<div class="space-y-4">
    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Jahr">
            @foreach(range((int) now('Europe/Berlin')->year, (int) now('Europe/Berlin')->year - 5) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
        <select wire:model.live="source" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Herkunft">
            <option value="">Alles</option>@foreach($sources as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
        </select>
        <a href="{{ route('hours.csv', ['jahr' => $year]) }}" class="text-sm text-fw-navy underline">CSV</a>
    </div>
    <section class="rounded-2xl border border-gray-200 bg-white p-5">
        <p class="text-sm text-gray-500">Geleistete Stunden {{ $year }}</p>
        <p class="text-3xl font-bold text-gray-900">{{ \App\Services\HoursRegistry::format($totals->sum()) }}</p>
        <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            @foreach($totals as $source => $minutes)<dt class="text-gray-500">{{ $source }}</dt><dd class="text-right text-gray-900">{{ \App\Services\HoursRegistry::format($minutes) }}</dd>@endforeach
        </dl>
        <p class="mt-2 text-xs text-gray-500">Kommt von selbst aus der Anwesenheit bei Diensten und Terminen, den Einsätzen, den Lehrgangstagen und den Schichten – nichts muss eigens eingetragen werden. Fehlt etwas, war die Anwesenheit nicht vermerkt.</p>
    </section>
    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-200 bg-white text-sm">
        @forelse($rows as $row)
            <div class="flex flex-wrap justify-between gap-2 p-3">
                <span><span class="text-gray-900">{{ $row['title'] }}</span><span class="block text-xs text-gray-500">{{ $row['date']->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $row['source'] }}</span></span>
                <span class="text-gray-700">{{ \App\Services\HoursRegistry::format($row['minutes']) }}</span>
            </div>
        @empty
            <p class="p-4 text-gray-500">Keine Stunden in {{ $year }}.</p>
        @endforelse
    </div>
</div>
