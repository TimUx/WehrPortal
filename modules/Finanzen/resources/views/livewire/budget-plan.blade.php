@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Budget & Kostenstellen</h1>
            <p class="text-sm text-gray-500">Für jede Kostenstelle den Betrag festlegen, der im Jahr ausgegeben werden darf. Alle Kameraden sehen auf der Übersicht, wie viel davon schon verplant ist.</p>
        </div>
        <select wire:model.live="year" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red" aria-label="Jahr">
            @foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
        </select>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @foreach(['center', 'year', 'budget', 'name'] as $field)@error($field)<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror @endforeach

    {{-- Budget of the year --}}
    <section>
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-gray-700">Budget {{ $year }}{{ $closed ? ' (abgeschlossen)' : '' }}</h2>
            @unless($closed)<button type="button" wire:click="copyPrevious" class="text-sm text-fw-navy underline">Budgets aus {{ $year - 1 }} übernehmen</button>@endunless
        </div>
        <form wire:submit="saveBudgets" class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($centers->where('is_active', true) as $c)
                @php($row = $overview[$c->id] ?? null)
                <div class="grid grid-cols-1 items-center gap-2 p-3 sm:grid-cols-[1fr_10rem]" wire:key="b-{{ $c->id }}">
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-gray-900">{{ $c->label() }}</p>
                        @if($row)<p class="text-xs text-gray-500">verplant {{ $money($row['approved'] + $row['paid']) }}{{ $row['income'] ? ' · Einnahmen '.$money($row['income']) : '' }}</p>@endif
                        @error('amounts.'.$c->id)<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="text" inputmode="decimal" wire:model="amounts.{{ $c->id }}" placeholder="0,00" @disabled($closed) class="{{ $input }} text-right"> €</label>
                </div>
            @empty
                <p class="p-4 text-sm text-gray-500">Noch keine Kostenstellen – unten anlegen.</p>
            @endforelse
            @if($centers->where('is_active', true)->isNotEmpty() && ! $closed)
                <div class="p-3 text-right"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Budget speichern</button></div>
            @endif
        </form>
    </section>

    {{-- Kostenstellen --}}
    <section class="space-y-2">
        <h2 class="text-sm font-semibold text-gray-700">Kostenstellen</h2>
        <form wire:submit="saveCenter" class="grid grid-cols-1 gap-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm sm:grid-cols-[8rem_1fr]">
            <p class="text-sm font-semibold text-gray-900 sm:col-span-2">{{ $editingId ? 'Kostenstelle ändern' : 'Neue Kostenstelle' }}</p>
            <input type="text" wire:model="center.code" maxlength="20" placeholder="Nr. (optional)" class="{{ $input }}">
            <input type="text" wire:model="center.name" maxlength="100" placeholder="Name, z. B. Jugendfeuerwehr" class="{{ $input }}">
            <textarea wire:model="center.description" rows="2" placeholder="Wofür (optional)" class="{{ $input }} sm:col-span-2"></textarea>
            <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="center.is_active" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> aktiv (wählbar)</label>
                <button type="submit" class="ml-auto rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">{{ $editingId ? 'Speichern' : 'Anlegen' }}</button>
                @if($editingId)<button type="button" wire:click="cancelEdit" class="text-sm text-gray-500 underline">Abbrechen</button>@endif
            </div>
        </form>
        <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @foreach($centers as $c)
                <li class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" wire:key="c-{{ $c->id }}">
                    <span class="min-w-0">
                        <span class="block font-medium {{ $c->is_active ? 'text-gray-900' : 'text-gray-400' }}">{{ $c->label() }}{{ $c->is_active ? '' : ' (aus)' }}</span>
                        @if($c->description)<span class="block text-xs text-gray-500">{{ $c->description }}</span>@endif
                    </span>
                    <span class="flex items-center gap-3 text-xs">
                        <span class="text-gray-500">{{ $c->entries_count }} Buchung{{ $c->entries_count === 1 ? '' : 'en' }}</span>
                        <button type="button" wire:click="edit({{ $c->id }})" class="text-fw-navy underline">Ändern</button>
                        @if($c->entries_count === 0)<button type="button" wire:click="deleteCenter({{ $c->id }})" wire:confirm="„{{ $c->name }}“ löschen?" class="text-red-600 underline">Löschen</button>@endif
                    </span>
                </li>
            @endforeach
        </ul>
    </section>
</div>
