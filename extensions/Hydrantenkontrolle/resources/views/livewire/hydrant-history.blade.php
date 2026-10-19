<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold">Kontrollhistorie · Nr. {{ $hydrant->number }}</h1>
            <p class="text-sm text-gray-600">{{ $hydrant->city }} · {{ $hydrant->street }} {{ $hydrant->house_number }}{{ $hydrant->location_description ? ' · '.$hydrant->location_description : '' }}</p>
        </div>
        <a href="{{ route('hydrantenkontrolle.edit', $hydrant) }}" class="inline-flex text-fw-navy">Zu den Stammdaten</a>
    </div>
    <div class="divide-y rounded-2xl border bg-white">
        @forelse($inspections as $inspection)
            <article wire:key="history-{{ $inspection->id }}" class="space-y-2 p-4">
                <h2 class="font-semibold">{{ $inspection->year->year }}</h2>
                <p>{{ $inspection->statusLabel() }} · {{ $inspection->checked_at?->format('d.m.Y') ?? 'Kein Prüfdatum' }} · {{ $inspection->inspector_name ?? 'Noch kein Prüfer' }}</p>
                @if($inspection->defects)
                    <ul class="list-inside list-disc text-sm text-gray-700">
                        @foreach($inspection->defects as $defect)
                            <li>{{ $defects[$defect] ?? $defect }}</li>
                        @endforeach
                    </ul>
                @endif
                @if($inspection->notes)
                    <p class="whitespace-pre-line text-sm text-gray-700">{{ $inspection->notes }}</p>
                @endif
            </article>
        @empty
            <p class="p-4 text-gray-600">Für diesen Hydranten gibt es noch keine Jahreskontrollen.</p>
        @endforelse
    </div>
    <p class="text-sm text-gray-600">Pro Jahr wird der zuletzt gespeicherte Stand angezeigt. Einzelne Änderungen innerhalb eines Jahres stehen im Aktivitätslog.</p>
</div>
