<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Einsätze {{ $year }}</h1>
            <p class="text-sm text-gray-500">{{ $reports->count() }} {{ $reports->count() === 1 ? 'Einsatz' : 'Einsätze' }}</p>
        </div>
        <button type="button" wire:click="create" class="inline-flex items-center rounded-lg bg-fw-red px-4 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">+ Neuer Einsatz</button>
    </div>
    @error('report')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    {{-- Statusliste: how many in which state, and what is still open per Einsatzart --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach(\Modules\Einsatzbericht\src\Models\Report::STATUSES as $value => $label)
            <button type="button" wire:click="$set('status', '{{ $status === $value ? '' : $value }}')" class="rounded-xl border px-3 py-2 text-left {{ $status === $value ? 'border-fw-navy bg-blue-50' : 'border-gray-100 bg-white' }}">
                <span class="block text-xs text-gray-500">{{ $value === 'closed' ? 'Zur Freigabe' : $label }}</span>
                <span class="text-lg font-bold text-gray-900">{{ $overview['states'][$value] ?? 0 }}</span>
            </button>
        @endforeach
    </div>
    @if($overview['openByCategory']->isNotEmpty())
        <p class="text-xs text-gray-600">Noch nicht freigegeben: {{ $overview['openByCategory']->map(fn ($r) => $r['label'].' '.$r['count'])->implode(' · ') }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm">@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
        <select wire:model.live="status" class="rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm">
            <option value="">Alle</option>@foreach(\Modules\Einsatzbericht\src\Models\Report::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Stichwort, Adresse, Nummer" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm">
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @forelse($reports as $report)
            @php($strength = \Modules\Einsatzbericht\src\Services\ReportService::reportStrength($report))
            <a href="{{ route('einsatzbericht.show', $report) }}" class="block px-4 py-3 hover:bg-gray-50" wire:key="r-{{ $report->id }}">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-semibold text-gray-900">{{ $report->keyword ?: 'Ohne Stichwort' }} <span class="font-normal text-gray-500">· {{ $report->number }}</span>
                            @if($report->parent_id)<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-normal text-gray-600">Nebenbericht{{ $report->side_label ? ': '.$report->side_label : '' }}</span>@endif
                            @if($report->released_signature)<span class="ml-1 text-xs font-normal text-green-700">signiert</span>@endif</p>
                        <p class="text-sm text-gray-600">{{ $report->alarmed_at?->setTimezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'ohne Zeit' }} · {{ $report->address ?: 'ohne Adresse' }}</p>
                        <p class="text-xs text-gray-500">{{ $report->categoryLabel() }} · Stärke {{ $strength['label'] }} · {{ $report->vehicles->reject->is_standby->pluck('name')->implode(', ') ?: 'keine Fahrzeuge' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ ['draft' => 'bg-gray-100 text-gray-700', 'active' => 'bg-red-100 text-red-800', 'closed' => 'bg-amber-100 text-amber-800', 'released' => 'bg-green-100 text-green-800'][$report->status] }}">{{ $report->statusLabel() }}</span>
                </div>
            </a>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500">Keine Einsätze gefunden.</p>
        @endforelse
    </div>
</div>
