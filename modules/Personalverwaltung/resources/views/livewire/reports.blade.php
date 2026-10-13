@php($input = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm')
<div class="mx-auto max-w-4xl space-y-4">
    <div class="print:hidden">
        <h1 class="text-xl font-bold text-gray-900">Auswertungen</h1>
        <p class="text-sm text-gray-500">Für Kommune, Kreis- oder Landesverband – direkt aus der Diensthistorie, im Rahmen deiner Abteilungen. Drucken oder als CSV für Excel.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 print:hidden">
        <select wire:model.live="kind" class="{{ $input }}" aria-label="Auswertung">@foreach(\Modules\Personalverwaltung\src\Services\PersonnelService::REPORTS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        @if($periodic)
            <input type="date" wire:model.live="from" class="{{ $input }}" aria-label="von"> <span class="text-sm text-gray-500">bis</span> <input type="date" wire:model.live="to" class="{{ $input }}" aria-label="bis">
        @endif
        <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Drucken</button>
        <a href="{{ route('personalverwaltung.reports.csv', ['art' => $kind, 'von' => $from, 'bis' => $to]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">CSV</a>
    </div>
    <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="text-lg font-bold text-gray-900">{{ $title }}{{ $periodic ? ' '.\Illuminate\Support\Carbon::parse($from)->format('d.m.Y').' – '.\Illuminate\Support\Carbon::parse($to)->format('d.m.Y') : ' – Stand '.now('Europe/Berlin')->format('d.m.Y') }}</h2>
        <p class="text-xs text-gray-500">{{ app(\App\Services\OrganizationSettings::class)->name() }} · {{ $rows->flatten(1)->count() }} Einträge</p>
        @forelse($rows as $group => $items)
            <h3 class="mt-3 text-sm font-semibold text-gray-800">{{ $group }} <span class="font-normal text-gray-500">({{ $items->count() }})</span></h3>
            <table class="mt-1 w-full text-sm">
                <tbody class="divide-y divide-gray-100">
                    @foreach($items as $row)<tr><td class="py-1">{{ $row['name'] }}</td><td class="py-1 text-gray-600">{{ $row['detail'] }}</td><td class="py-1 text-right text-gray-600">{{ $row['date']?->format('d.m.Y') }}</td></tr>@endforeach
                </tbody>
            </table>
        @empty
            <p class="mt-3 text-sm text-gray-500">Nichts gefunden.</p>
        @endforelse
    </div>
</div>
