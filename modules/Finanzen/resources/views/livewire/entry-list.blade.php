@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Buchungen</h1>
            <p class="text-sm text-gray-500">Alle Ausgaben und Einnahmen mit Belegen. „Zu genehmigen“ zeigt die wartenden Ausgaben aller Jahre.</p>
        </div>
        @if($canManage)<a href="{{ route('finanzen.book') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Buchung erfassen</a>@endif
    </div>
    {{-- Without the Jahresabschluss the export lives here --}}
    @unless($reportOn)
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('finanzen.export.csv', $year) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50">Buchungen {{ $year }} als CSV</a>
            <a href="{{ route('finanzen.export.zip', $year) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 font-semibold text-gray-700 hover:bg-gray-50">Belege {{ $year }} als ZIP</a>
        </div>
    @endunless

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="$set('status', 'submitted')" class="rounded-full px-3 py-1.5 text-sm {{ $status === 'submitted' ? 'bg-amber-500 text-white' : 'border border-amber-300 bg-amber-50 text-amber-900' }}">Zu genehmigen ({{ $waiting }})</button>
        <select wire:model.live="year" class="{{ $select }}" aria-label="Jahr" @disabled($status === 'submitted')>@foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach</select>
        @if($locationsEnabled)
            <select wire:model.live="locationId" class="{{ $select }}" aria-label="Standort">
                @if(\Modules\Finanzen\src\Services\FinanceService::canReviewGlobally(auth()->user()))<option value="">Alle Standorte</option>@endif
                @foreach($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
            </select>
        @endif
        <select wire:model.live="status" class="{{ $select }}" aria-label="Status">
            <option value="">Alle Status</option>
            @foreach(\Modules\Finanzen\src\Models\Entry::STATUSES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="kind" class="{{ $select }}" aria-label="Art">
            <option value="">Ausgaben und Einnahmen</option>
            @foreach(\Modules\Finanzen\src\Models\Entry::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}n</option>@endforeach
        </select>
        <select wire:model.live="center" class="{{ $select }}" aria-label="Kostenstelle">
            <option value="">Alle Kostenstellen</option>
            @foreach($centers as $c)<option value="{{ $c->id }}">{{ $c->label() }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Suchen" class="{{ $select }} w-full sm:w-48">
    </div>

    <p class="text-sm text-gray-600">Genehmigt und bezahlt: Ausgaben <strong>{{ $money($sumExpense) }}</strong> · Einnahmen <strong class="text-green-700">{{ $money($sumIncome) }}</strong> · Saldo <strong>{{ $money($sumIncome - $sumExpense) }}</strong></p>

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($entries as $entry)
            <a href="{{ route('finanzen.entry', $entry) }}" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 p-3 text-sm hover:bg-gray-50" wire:key="e-{{ $entry->id }}">
                <span class="min-w-0">
                    <span class="block font-medium text-gray-900">{{ $entry->title }}</span>
                    <span class="block text-xs text-gray-500">{{ $entry->code() }} · {{ $entry->booked_on->format('d.m.Y') }} · {{ $entry->costCenter->name }}{{ $locationsEnabled ? ' · '.($entry->location?->name ?? 'ohne Standort') : '' }}{{ $entry->submitter ? ' · '.$entry->submitter->name : '' }}{{ $entry->receipts_count ? '' : ' · ohne Beleg' }}</span>
                </span>
                <span class="flex flex-col items-end gap-1">
                    <span class="font-semibold {{ $entry->isIncome() ? 'text-green-700' : 'text-gray-900' }}">{{ $entry->isIncome() ? '+' : '' }}{{ $entry->amount() }}</span>
                    @include('finanzen::partials.status', ['entry' => $entry])
                </span>
            </a>
        @empty
            <p class="p-4 text-sm text-gray-500">Keine Buchungen.</p>
        @endforelse
    </div>
    {{ $entries->links() }}
</div>
