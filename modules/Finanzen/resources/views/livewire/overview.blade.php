@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Budget {{ $year }}</h1>
            <p class="text-sm text-gray-500">Wie viel jede Kostenstelle geplant hat und was davon schon ausgegeben ist – für alle Kameraden sichtbar.@if($closed) <strong>Das Jahr ist abgeschlossen.</strong>@endif</p>
        </div>
        <div class="flex items-center gap-2">
            <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red" aria-label="Jahr">
                @foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
            <a href="{{ route('finanzen.submit') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Ausgabe einreichen</a>
        </div>
    </div>

    @if($toReview > 0)
        <a href="{{ route('finanzen.entries', ['status' => 'submitted']) }}" class="block rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-100">{{ $toReview }} Ausgabe{{ $toReview === 1 ? '' : 'n' }} {{ $toReview === 1 ? 'wartet' : 'warten' }} auf Genehmigung →</a>
    @endif

    {{-- Totals --}}
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach(['budget' => 'Budget', 'paid' => 'Ausgegeben', 'approved' => 'Genehmigt, offen', 'available' => 'Noch verfügbar'] as $key => $label)
            <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-lg font-bold {{ $key === 'available' && $totals['available'] < 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $money($totals[$key]) }}</p>
            </div>
        @endforeach
    </section>

    {{-- Kostenstellen --}}
    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-700">Kostenstellen</h2>
        @if($overview->isEmpty())
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine Kostenstellen angelegt.@if($canManage) <a href="{{ route('finanzen.budget') }}" class="text-fw-navy underline">Kostenstellen und Budget anlegen</a>@endif</p>
        @else
            <ul class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach($overview as $row)
                    <li class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" wire:key="cc-{{ $row['center']->id }}">
                        <div class="flex items-baseline justify-between gap-2">
                            <p class="font-semibold text-gray-900">{{ $row['center']->label() }}</p>
                            <p class="text-sm text-gray-500">{{ $row['percent'] !== null ? $row['percent'].' %' : 'kein Budget' }}</p>
                        </div>
                        @include('finanzen::partials.bar', ['row' => $row])
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-0.5 text-sm">
                            <dt class="text-gray-500">Budget</dt><dd class="text-right">{{ $money($row['budget']) }}</dd>
                            <dt class="text-gray-500">Ausgegeben</dt><dd class="text-right">{{ $money($row['paid']) }}</dd>
                            @if($row['approved'])<dt class="text-gray-500">Genehmigt, offen</dt><dd class="text-right">{{ $money($row['approved']) }}</dd>@endif
                            <dt class="text-gray-500">Verfügbar</dt><dd class="text-right font-semibold {{ $row['available'] < 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $money($row['available']) }}</dd>
                            @if($row['income'])<dt class="text-gray-500">Einnahmen</dt><dd class="text-right text-green-700">{{ $money($row['income']) }}</dd>@endif
                        </dl>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Own Ausgaben --}}
    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-700">Meine Ausgaben</h2>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($mine as $entry)
                <a href="{{ route('finanzen.entry', $entry) }}" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm hover:bg-gray-50" wire:key="m-{{ $entry->id }}">
                    <span class="min-w-0">
                        <span class="block font-medium text-gray-900">{{ $entry->title }}</span>
                        <span class="block text-xs text-gray-500">{{ $entry->code() }} · {{ $entry->booked_on->format('d.m.Y') }} · {{ $entry->costCenter->name }}</span>
                    </span>
                    <span class="flex items-center gap-3"><span class="font-semibold">{{ $entry->amount() }}</span>@include('finanzen::partials.status', ['entry' => $entry])</span>
                </a>
            @empty
                <p class="p-4 text-sm text-gray-500">Du hast {{ $year }} keine Ausgaben eingereicht. Hast du etwas für die Wehr ausgelegt? <a href="{{ route('finanzen.submit') }}" class="text-fw-navy underline">Beleg einreichen</a></p>
            @endforelse
        </div>
    </section>

    {{-- Transparency: every approved and paid Ausgabe --}}
    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-700">Ausgaben {{ $year }}</h2>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($spent as $entry)
                <div class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" wire:key="s-{{ $entry->id }}">
                    <span class="min-w-0">
                        <span class="block text-gray-900">{{ $entry->title }}</span>
                        <span class="block text-xs text-gray-500">{{ $entry->booked_on->format('d.m.Y') }} · {{ $entry->costCenter->name }}{{ $entry->status === 'approved' ? ' · genehmigt, noch nicht bezahlt' : '' }}</span>
                    </span>
                    <span class="font-medium text-gray-900">{{ $entry->amount() }}</span>
                </div>
            @empty
                <p class="p-4 text-sm text-gray-500">Noch keine Ausgaben in {{ $year }}.</p>
            @endforelse
        </div>
    </section>
</div>
