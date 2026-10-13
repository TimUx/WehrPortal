<div class="space-y-6">
    <div>
        <a href="{{ route('lehrgaenge.members') }}" class="text-sm text-gray-500 hover:text-gray-900">← Lehrgänge der Kameraden</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $member->name }}</h1>
        <p class="text-sm text-gray-500">{{ $member->rank ?: 'Kein Dienstgrad' }}@if($member->groups->isNotEmpty()) · {{ $member->groups->pluck('name')->implode(', ') }}@endif</p>
    </div>

    <section>
        <h2 class="text-sm font-semibold text-gray-700 mb-2">Absolvierte Lehrgänge</h2>
        @if($flash)<p class="mb-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
        @if($completed->isNotEmpty())
            @include('lehrgaenge::partials.completed', ['records' => $completed, 'canDelete' => $canManage])
        @else
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine.</p>
        @endif
        @if($canManage)
            <form wire:submit="addCompleted" class="mt-3 space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                <p class="text-sm font-semibold text-gray-900">Absolvierten Lehrgang nachtragen</p>
                <p class="text-xs text-gray-500">Für Lehrgänge aus der Zeit vor der App, aus einem anderen Kreis oder von der Landesfeuerwehrschule. Ein Lehrgang, der nicht in der Liste steht, wird mit seinem Namen angelegt.</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                    <input type="text" wire:model="entry.course" list="lq-course-names" maxlength="150" placeholder="Lehrgang" class="sm:col-span-2 w-full rounded-lg border-gray-300 text-sm">
                    <input type="date" wire:model="entry.completed_on" max="{{ now('Europe/Berlin')->toDateString() }}" class="w-full rounded-lg border-gray-300 text-sm">
                </div>
                <datalist id="lq-course-names">@foreach($courseNames as $name)<option value="{{ $name }}">@endforeach</datalist>
                <input type="text" wire:model="entry.note" maxlength="500" placeholder="Bemerkung, z. B. Ort oder Nachweis (optional)" class="w-full rounded-lg border-gray-300 text-sm">
                @foreach(['entry_course', 'entry_date'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
                <button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Als absolviert eintragen</button>
            </form>
        @endif
    </section>

    @if($open->isNotEmpty())
        <section>
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Laufende Anmeldungen</h2>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100 text-sm">
                @foreach($open as $record)
                    <div class="p-3">
                        <p><span class="font-medium text-gray-900">{{ $record->course->name }}</span> <span class="text-gray-500">· {{ $record->statusLabel() }}{{ $record->status === 'requested' ? ' am '.$record->created_at->setTimezone('Europe/Berlin')->format('d.m.Y') : '' }}</span></p>
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
