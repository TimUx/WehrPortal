<div class="space-y-4" wire:poll.20s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-3xl font-bold text-gray-900">Objektpläne</h1>
        <a href="{{ route('objektplaene.index') }}" class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-700">Kiosk verlassen</a>
    </div>

    @forelse($running as $row)
        <section class="space-y-3 rounded-2xl border-4 border-fw-red bg-white p-4 shadow-sm">
            <p class="text-xl font-bold text-fw-red">Einsatz {{ $row['report']->keyword ?: $row['report']->number }}@if($row['report']->address) · {{ $row['report']->address }}@endif</p>
            @foreach($row['objects'] as $hit)
                @include('objektplaene::partials.object-card', ['object' => $hit['object'], 'meters' => $hit['meters'], 'big' => true])
            @endforeach
        </section>
    @empty
        <p class="rounded-2xl border border-gray-200 bg-white p-4 text-lg text-gray-600">Kein laufender Einsatz an einem Objekt mit Plänen. Bei Alarm erscheinen die Pläne hier von selbst.</p>
    @endforelse

    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Objekt oder Adresse suchen" class="w-full rounded-xl border border-gray-300 px-4 py-3 text-lg focus:border-fw-red focus:ring-fw-red">
    <div class="space-y-3">
        @foreach($found as $object)
            @include('objektplaene::partials.object-card', ['object' => $object, 'big' => true])
        @endforeach
    </div>
</div>
