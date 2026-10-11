<div class="space-y-6">
    <div>
        <a href="{{ route('lehrgaenge.members') }}" class="text-sm text-gray-500 hover:text-gray-900">← Lehrgänge der Kameraden</a>
        <h1 class="text-xl font-bold text-gray-900 mt-1">{{ $member->name }}</h1>
        <p class="text-sm text-gray-500">{{ $member->rank ?: 'Kein Dienstgrad' }}@if($member->groups->isNotEmpty()) · {{ $member->groups->pluck('name')->implode(', ') }}@endif</p>
    </div>

    <section>
        <h2 class="text-sm font-semibold text-gray-700 mb-2">Absolvierte Lehrgänge</h2>
        @if($completed->isNotEmpty())
            @include('lehrgaenge::partials.completed', ['records' => $completed])
        @else
            <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine.</p>
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
