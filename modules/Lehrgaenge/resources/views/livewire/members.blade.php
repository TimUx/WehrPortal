<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold text-gray-900">Lehrgänge der Kameraden</h1>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name suchen" class="w-full sm:w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-fw-red focus:ring-fw-red">
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    @if($canManage)
        {{-- One Lehrgang for many Kameraden at once, e.g. when the old Lehrgänge go in for the first time --}}
        <details class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" @if($bulkCourse !== '' || $errors->any()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-gray-900">Lehrgang für mehrere Kameraden nachtragen</summary>
            <form wire:submit="addMany" class="mt-3 space-y-3">
                <p class="text-xs text-gray-500">Zum Einpflegen bereits absolvierter Lehrgänge: Lehrgang wählen, Kameraden ankreuzen, fertig. Das Datum ist freiwillig – ohne Datum steht „Datum unbekannt“ und es gibt keine Erinnerung an eine Auffrischung. Wer den Lehrgang schon hat, wird übersprungen.</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <input type="text" wire:model.live.debounce.400ms="bulkCourse" list="lq-bulk-course-names" maxlength="150" placeholder="Lehrgang, z. B. Truppmann" class="sm:col-span-2 w-full rounded-lg border-gray-300 text-sm">
                    <input type="date" wire:model="bulkDate" max="{{ now('Europe/Berlin')->toDateString() }}" class="w-full rounded-lg border-gray-300 text-sm" aria-label="absolviert am (optional)" title="Leer lassen, wenn das Datum nicht bekannt ist">
                </div>
                <datalist id="lq-bulk-course-names">@foreach($courseNames as $name)<option value="{{ $name }}">@endforeach</datalist>
                <input type="text" wire:model="bulkNote" maxlength="500" placeholder="Bemerkung, z. B. „aus der alten Liste übernommen“ (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                @if($bulkCourse !== '')
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm text-gray-700">Kameraden <span class="text-gray-500">({{ count($bulkMembers) }} gewählt)</span></span>
                            <span class="flex items-center gap-2">
                                <select wire:model.live="bulkGroup" class="rounded-lg border border-gray-300 px-2 py-1 text-sm" aria-label="Abteilung"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                                <button type="button" wire:click="selectAll" class="text-sm text-fw-navy underline">Alle ohne den Lehrgang wählen</button>
                                <button type="button" wire:click="$set('bulkMembers', [])" class="text-sm text-gray-500 underline">Keine</button>
                            </span>
                        </div>
                        <div class="mt-1 grid max-h-72 grid-cols-1 gap-x-4 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2">
                            @foreach($candidates as $m)
                                <label class="flex items-center gap-2 py-0.5 text-sm {{ $m->has_course ? 'text-gray-400' : '' }}" wire:key="bm-{{ $m->id }}">
                                    <input type="checkbox" wire:model="bulkMembers" value="{{ $m->id }}" @disabled($m->has_course) class="rounded border-gray-300 text-fw-red"> {{ $m->name }}@if($m->has_course) <span class="text-xs">(hat ihn schon)</span>@endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                @foreach(['entry_course', 'entry_date', 'entry_members'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
                <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Als absolviert eintragen</button>
            </form>
        </details>
    @endif

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
        @forelse($members as $member)
            <a href="{{ route('lehrgaenge.member', $member) }}" class="flex items-center justify-between gap-2 p-3 hover:bg-gray-50" wire:key="m-{{ $member->id }}">
                <span class="font-medium text-gray-900">{{ $member->name }}</span>
                <span class="text-gray-500">{{ $member->completed }} absolviert</span>
            </a>
        @empty
            <p class="p-3 text-gray-500">Niemand gefunden.</p>
        @endforelse
    </div>
</div>
