<div class="space-y-6">
    <div>
        <a href="{{ route('lehrgaenge.members') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Lehrgänge der Kameraden</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $member->name }}</h1>
        <p class="text-sm text-gray-500">{{ $member->rank ?: 'Kein Dienstgrad' }}@if($member->groups->isNotEmpty()) · {{ $member->groups->pluck('name')->implode(', ') }}@endif</p>
    </div>

    <section>
        <h2 class="text-sm font-semibold text-gray-700 mb-2">Absolvierte Lehrgänge</h2>
        @if($flash)<p class="mb-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
        @if($completed->isNotEmpty())
            @include('lehrgaenge::partials.completed', ['records' => $completed, 'canManage' => $canManage])
        @else
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine.</p>
        @endif
        @if($canManage)
            <form wire:submit="addCompleted" class="mt-3 space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-900">Absolvierten Lehrgang nachtragen</p>
                <p class="text-xs text-gray-500">Für Lehrgänge aus der Zeit vor der App, aus einem anderen Kreis oder von der Landesfeuerwehrschule. Ein Lehrgang, der nicht in der Liste steht, wird mit seinem Namen angelegt. Das Datum ist freiwillig – ohne Datum gibt es keine Erinnerung an eine Auffrischung. Viele Kameraden auf einmal: unter <a href="{{ route('lehrgaenge.members') }}" class="underline">Lehrgänge der Kameraden</a>.</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <input type="text" wire:model="entry.course" list="lq-course-names" maxlength="150" placeholder="Lehrgang" class="sm:col-span-2 w-full rounded-lg border-gray-300 text-sm">
                    <input type="date" wire:model="entry.completed_on" max="{{ now('Europe/Berlin')->toDateString() }}" class="w-full rounded-lg border-gray-300 text-sm" aria-label="absolviert am (optional)" title="Leer lassen, wenn das Datum nicht bekannt ist">
                </div>
                <datalist id="lq-course-names">@foreach($courseNames as $name)<option value="{{ $name }}">@endforeach</datalist>
                <input type="text" wire:model="entry.note" maxlength="500" placeholder="Bemerkung, z. B. Ort oder Nachweis (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                @foreach(['entry_course', 'entry_date'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
                <button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Als absolviert eintragen</button>
            </form>
        @endif
    </section>

    @if($deletions->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Gelöschte Lehrgänge</h2>
            <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
                @foreach($deletions as $d)
                    <li class="p-3"><span class="font-medium text-gray-900">{{ $d->course_name }}</span>@if($d->completed_on)<span class="text-gray-500"> · absolviert {{ $d->completed_on->format('d.m.Y') }}</span>@endif
                        <span class="block text-xs text-gray-600">Gelöscht am {{ $d->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}{{ $d->deleter ? ' von '.$d->deleter->name : '' }}: {{ $d->reason }}</span></li>
                @endforeach
            </ul>
        </section>
    @endif

    @if($open->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Laufende Anmeldungen</h2>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
                @foreach($open as $record)
                    <div class="p-3">
                        <p><span class="font-medium text-gray-900">{{ $record->course->name }}</span> <span class="text-gray-500">· {{ $record->statusLabel() }}{{ $record->status === 'requested' ? ' seit '.($record->waitlisted_at ?? $record->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y') : '' }}</span></p>
                        @if($record->sessions->isNotEmpty())<ul class="text-xs text-gray-600">@foreach($record->sessions as $session)<li>{{ $session->label() }}</li>@endforeach</ul>@endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($cancelled->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Absagen und Ablehnungen</h2>
            @include('lehrgaenge::partials.cancellations', ['records' => $cancelled, 'showName' => false])
        </section>
    @endif
</div>
