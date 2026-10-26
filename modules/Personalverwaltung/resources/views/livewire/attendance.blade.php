@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Dienstbeteiligung {{ $year }}</h1>
            <p class="text-sm text-gray-500">Aus dem Kalender: Termine der gewählten Arten, für die der Kamerad eingeladen war und bei denen die Anwesenheit erfasst wurde; vor dem Eintritt zählt nichts.@if($average !== null) Durchschnitt: {{ round($average) }} %.@endif</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <label>Jahr <input type="number" wire:model.live="year" min="1990" max="2100" class="{{ $input }} w-28"></label>
            <a href="{{ route('personalverwaltung.attendance.csv', ['jahr' => $year, 'arten' => $csvTypes]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 font-semibold text-gray-700">CSV</a>
        </div>
    </div>
    <div class="flex flex-wrap gap-4 text-sm text-gray-700">
        @foreach($typeOptions as $v => $l)
            <label class="flex items-center gap-2"><input type="checkbox" value="{{ $v }}" wire:model.live="types" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $l }}</label>
        @endforeach
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead><tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-4 py-2">Name</th><th class="px-4 py-2">Abteilungen</th><th class="px-4 py-2 text-right">Anwesend</th><th class="px-4 py-2 w-1/3">Beteiligung</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($rows as $r)
                    <tr wire:key="a-{{ $r['user']->id }}">
                        <td class="px-4 py-2"><a href="{{ route('personalverwaltung.member', $r['user']) }}" class="font-medium text-gray-900 hover:underline">{{ $r['user']->name }}</a></td>
                        <td class="px-4 py-2 text-gray-600">{{ $r['user']->groups->pluck('display_name')->implode(', ') ?: '–' }}</td>
                        <td class="px-4 py-2 text-right text-gray-600">{{ $r['total'] ? $r['attended'].' / '.$r['total'] : '–' }}</td>
                        <td class="px-4 py-2">
                            @if($r['percent'] !== null)
                                <span class="flex items-center gap-2"><span class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full {{ $r['percent'] >= 60 ? 'bg-green-600' : ($r['percent'] >= 30 ? 'bg-amber-500' : 'bg-red-600') }}" style="width: {{ $r['percent'] }}%"></span></span><span class="w-12 text-right font-semibold">{{ $r['percent'] }} %</span></span>
                            @else<span class="text-gray-400">–</span>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
