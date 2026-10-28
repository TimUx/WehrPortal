@php($select = 'rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    @unless($personal)
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-gray-900">{{ $all ? 'Verbandbuch' : 'Meine Einträge' }}</h1>
                <p class="text-sm text-gray-500">
                    @if($all)
                        @if(config('locations.enabled'))
                            Einträge deiner Standorte.
                        @endif
                        Alle Verletzungen, Unfälle und Vorfälle nach DGUV Information 204-021. Gesundheitsdaten sind verschlüsselt; jeder Zugriff wird protokolliert.
                    @else
                        Einträge, in denen du verletzt wurdest, Erste Hilfe geleistet oder den Eintrag geschrieben hast. Andere Einträge sehen nur GBI/SBI, Admins und die zuständige Wehrführung.
                    @endif
                </p>
            </div>
            <a href="{{ route('verbandbuch.create') }}" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Eintrag erfassen</a>
        </div>
    @else
        <p class="text-sm text-gray-500">Einträge im Verbandbuch, in denen du als verletzte Person stehst.</p>
    @endunless

    @if($all)
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="$set('status', '{{ $status === 'report' ? '' : 'report' }}')" class="rounded-full px-3 py-1.5 text-sm {{ $status === 'report' ? 'bg-amber-500 text-white' : 'border border-amber-300 bg-amber-50 text-amber-900' }}">Unfallanzeige offen ({{ $awaiting }})</button>
            <select wire:model.live="year" class="{{ $select }}" aria-label="Jahr" @disabled($status === 'report')>
                <option value="">Alle Jahre</option>
                @foreach($years as $y)<option value="{{ $y }}">{{ $y }}</option>@endforeach
            </select>
            <select wire:model.live="kind" class="{{ $select }}" aria-label="Art">
                <option value="">Alle Arten</option>
                @foreach(\Modules\Verbandbuch\src\Models\Entry::KINDS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <select wire:model.live="status" class="{{ $select }}" aria-label="Status">
                <option value="">Alle</option>
                <option value="report">Unfallanzeige offen</option>
                <option value="voided">Storniert</option>
            </select>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder Ort" class="{{ $select }} w-full sm:w-48">
            @if($year)<a href="{{ route('verbandbuch.print-year', $year) }}" target="_blank" class="text-sm text-fw-navy underline">Verbandbuch {{ $year }} drucken</a>@endif
        </div>
    @endif

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($entries as $entry)
            <a href="{{ route('verbandbuch.show', $entry) }}" class="grid grid-cols-[1fr_auto] items-center gap-x-3 gap-y-1 p-3 text-sm hover:bg-gray-50" wire:key="e-{{ $entry->id }}">
                <span class="min-w-0">
                    <span class="block font-medium {{ $entry->isVoided() ? 'text-gray-400 line-through' : 'text-gray-900' }}">{{ $entry->kindLabel() }}{{ $entry->injured_name ? ' – '.$entry->injured_name : '' }}</span>
                    <span class="block text-xs text-gray-500">Nr. {{ $entry->code() }} · {{ $entry->occurred_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ $entry->occasionLabel() }} · {{ $entry->location }}</span>
                </span>
                <span class="flex flex-col items-end gap-1 text-xs font-semibold">
                    @if($entry->isVoided())<span class="rounded-full bg-gray-200 px-2 py-0.5 text-gray-700">Storniert</span>
                    @elseif($entry->needsReport())<span class="rounded-full bg-amber-100 px-2 py-0.5 text-amber-900">Unfallanzeige offen</span>
                    @endif
                </span>
            </a>
        @empty
            <p class="p-4 text-sm text-gray-500">Keine Einträge.</p>
        @endforelse
    </div>
    {{ $entries->links() }}
</div>
