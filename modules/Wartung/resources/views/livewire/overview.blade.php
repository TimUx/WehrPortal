@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Fällige Wartungen</h1>
            <p class="text-sm text-gray-500">Prüfungen und Wartungen nach Vorlage, sobald sie in der Voranzeige sind, dazu geplante Ad-hoc-Wartungen. Überfällige stehen oben.</p>
        </div>
        @if($canPerform)<a href="{{ route('wartung.perform') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Ad-hoc-Wartung</a>@endif
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Überfällig</p><p class="text-2xl font-bold {{ $overdue ? 'text-red-700' : 'text-gray-900' }}">{{ $overdue }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Offen</p><p class="text-2xl font-bold text-gray-900">{{ $open }}</p></div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Protokolle {{ now('Europe/Berlin')->year }}</p><p class="text-2xl font-bold text-gray-900">{{ $year['count'] }}</p>@if($year['failed'])<p class="text-xs text-red-700">{{ $year['failed'] }} nicht bestanden</p>@endif</div>
        <div class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm"><p class="text-xs text-gray-500">Aufwand {{ now('Europe/Berlin')->year }}</p><p class="text-lg font-bold text-gray-900">{{ intdiv($year['minutes'], 60) }} h {{ $year['minutes'] % 60 }} min</p><p class="text-xs text-gray-500">{{ $money($year['cost']) }}</p></div>
    </div>

    @error('wartung')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror

    <div class="flex flex-wrap items-center gap-2">
        <select wire:model.live="filter" class="{{ $select }}" aria-label="Filter">
            <option value="">Alle offenen</option>
            <option value="overdue">Nur überfällige</option>
            <option value="external">Fachfirma</option>
        </select>
        <select wire:model.live="category" class="{{ $select }}" aria-label="Kategorie">
            <option value="">Alle Kategorien</option>
            @foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Gerät, Code oder Vorlage" class="{{ $select }} w-full sm:w-56">
    </div>

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($tasks as $task)
            <div class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 p-3 text-sm" wire:key="t-{{ $task->id }}">
                <div class="min-w-0">
                    <a href="{{ route('wartung.device', $task->equipment_id) }}" class="block font-medium text-gray-900 hover:underline">{{ $task->label() }} – {{ $task->equipment?->code }} {{ $task->equipment?->name }}</a>
                    <span class="block text-xs {{ $task->isOverdue() ? 'font-semibold text-red-700' : 'text-gray-500' }}">
                        {{ $task->isOverdue() ? 'Überfällig seit' : 'Fällig am' }} {{ $task->due_on->format('d.m.Y') }}
                        @if($task->equipment?->category) · {{ $task->equipment->category->name }}@endif
                        @if($task->provider) · Fachfirma {{ $task->provider->name }}@elseif($task->template?->performer === 'external') · Fachfirma @endif
                        @if($task->template?->required_qualification) · {{ $task->template->required_qualification }}@endif
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    @if($canPerform)<a href="{{ route('wartung.perform', ['aufgabe' => $task->id]) }}" class="rounded-lg bg-fw-navy px-3 py-1.5 text-xs font-semibold text-white">Durchführen</a>@endif
                    @if($canManage)<button type="button" wire:click="cancelTask({{ $task->id }})" wire:confirm="Diese Wartung verwerfen? Sie kommt mit dem nächsten Intervall wieder." class="text-xs text-gray-500 underline">verwerfen</button>@endif
                </div>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Keine offenen Wartungen.@if($canManage) Wartungen entstehen aus den <a href="{{ route('wartung.templates') }}" class="underline">Vorlagen</a>.@endif</p>
        @endforelse
    </div>
    {{ $tasks->links() }}

    @if($lifetime->isNotEmpty())
        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">DGUV-Lebensdauer läuft ab (nächste {{ \Modules\Wartung\src\Livewire\Overview::LIFETIME_DAYS }} Tage)</h2>
            <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
                @foreach($lifetime as $device)
                    <a href="{{ route('wartung.device', $device) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="l-{{ $device->id }}">
                        <span class="min-w-0 truncate">{{ $device->code }} {{ $device->name }}<span class="text-xs text-gray-500"> · {{ $device->category?->name }}, {{ $device->lifetimeYears() }} Jahre</span></span>
                        <span class="shrink-0 text-xs font-semibold {{ $device->lifetimeExpired() ? 'text-red-700' : 'text-amber-800' }}">{{ $device->lifetimeExpired() ? 'abgelaufen' : 'endet' }} {{ $device->lifetimeEndsAt()->format('d.m.Y') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
