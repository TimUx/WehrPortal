@php($f = fn (int $m) => \App\Services\HoursRegistry::format($m))
<div class="mx-auto max-w-5xl space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Stundennachweis {{ $year }}</h1>
            <p class="text-sm text-gray-500">Geleistete Stunden der aktiven Kameraden aus der Anwesenheit bei Diensten und Terminen, den Einsätzen, den Lehrgangstagen und den Schichten – z. B. für Ehrenamtskarte, Jahreshauptversammlung oder Gemeinde. Zusammen {{ $f($total) }}.</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Jahr">
                @foreach(range((int) now('Europe/Berlin')->year, (int) now('Europe/Berlin')->year - 5) as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
            <a href="{{ route('personalverwaltung.hours.csv', ['jahr' => $year]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">CSV</a>
        </div>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500"><tr><th class="p-2">Kamerad</th>@foreach($sources as $s)<th class="p-2 text-right">{{ $s }}</th>@endforeach<th class="p-2 text-right">Gesamt</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($members as $m)
                    @php($u = $byUser[$m->id] ?? collect())
                    <tr><td class="p-2"><a href="{{ route('personalverwaltung.member', $m->id) }}" class="text-fw-navy underline">{{ $m->name }}</a></td>
                        @foreach($sources as $s)<td class="p-2 text-right text-gray-700">{{ ($u[$s] ?? 0) ? $f($u[$s]) : '–' }}</td>@endforeach
                        <td class="p-2 text-right font-semibold text-gray-900">{{ $f($u->sum()) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
