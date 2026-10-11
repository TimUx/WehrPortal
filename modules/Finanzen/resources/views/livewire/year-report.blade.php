@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Jahresabschluss {{ $year }}</h1>
            <p class="text-sm text-gray-500">Budget und Ist je Kostenstelle, Einnahmen und Saldo. Export für Steuerberater und Behörden: alle genehmigten und bezahlten Buchungen als CSV (Excel) und alle Belege als ZIP.</p>
        </div>
        <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red" aria-label="Jahr">
            @foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('year')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <div class="flex flex-wrap gap-2 text-sm">
        <a href="{{ route('finanzen.print', $year) }}" target="_blank" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">Drucken / PDF</a>
        <a href="{{ route('finanzen.export.csv', $year) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">Buchungen als CSV</a>
        <a href="{{ route('finanzen.export.zip', $year) }}" class="rounded-lg border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-50">Belege als ZIP</a>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="w-full min-w-[40rem] text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                <tr><th class="px-3 py-2">Kostenstelle</th><th class="px-3 py-2 text-right">Budget</th><th class="px-3 py-2 text-right">Ausgegeben</th><th class="px-3 py-2 text-right">Genehmigt, offen</th><th class="px-3 py-2 text-right">Rest</th><th class="px-3 py-2 text-right">Einnahmen</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($overview as $row)
                    <tr wire:key="y-{{ $row['center']->id }}">
                        <td class="px-3 py-2">{{ $row['center']->label() }}</td>
                        <td class="px-3 py-2 text-right">{{ $money($row['budget']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $money($row['paid']) }}</td>
                        <td class="px-3 py-2 text-right">{{ $row['approved'] ? $money($row['approved']) : '–' }}</td>
                        <td class="px-3 py-2 text-right {{ $row['available'] < 0 ? 'font-semibold text-red-700' : '' }}">{{ $money($row['available']) }}</td>
                        <td class="px-3 py-2 text-right text-green-700">{{ $row['income'] ? $money($row['income']) : '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t-2 border-gray-200 font-semibold">
                <tr>
                    <td class="px-3 py-2">Summe</td>
                    <td class="px-3 py-2 text-right">{{ $money($totals['budget']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $money($totals['paid']) }}</td>
                    <td class="px-3 py-2 text-right">{{ $money($totals['approved']) }}</td>
                    <td class="px-3 py-2 text-right {{ $totals['available'] < 0 ? 'text-red-700' : '' }}">{{ $money($totals['available']) }}</td>
                    <td class="px-3 py-2 text-right text-green-700">{{ $money($totals['income']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <p class="text-sm text-gray-700">Einnahmen {{ $money($totals['income']) }} − Ausgaben {{ $money($totals['paid'] + $totals['approved']) }} = <strong>Saldo {{ $money($totals['income'] - $totals['paid'] - $totals['approved']) }}</strong></p>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Jahr abschließen</p>
        @if($closedAt)
            <p class="text-sm text-gray-700">Abgeschlossen am {{ \Illuminate\Support\Carbon::parse($closedAt->closed_at)->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $closedAt->name ? ' von '.$closedAt->name : '' }}. Buchungen und Budgets von {{ $year }} sind gesperrt.</p>
            @if(auth()->user()->is_admin)<button type="button" wire:click="reopen" wire:confirm="Jahr {{ $year }} wieder öffnen?" class="text-sm text-fw-navy underline">Wieder öffnen (Admin)</button>@endif
        @elseif($canManage)
            <p class="text-sm text-gray-600">Danach lässt sich in {{ $year }} nichts mehr ändern. @if($open)<strong class="text-amber-800">Vorher {{ $open }} offene Ausgabe{{ $open === 1 ? '' : 'n' }} entscheiden oder bezahlen.</strong>@endif</p>
            <button type="button" wire:click="close" wire:confirm="Jahr {{ $year }} abschließen? Danach sind alle Buchungen gesperrt." class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" @disabled($open > 0)>Jahr {{ $year }} abschließen</button>
        @else
            <p class="text-sm text-gray-600">Den Abschluss macht der Kassenwart.</p>
        @endif
    </section>
</div>
