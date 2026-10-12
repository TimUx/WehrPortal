{{-- Objektpläne at the Einsatzstelle, in the Einsatzbericht and the Lagemeldungen; needs the point of the Einsatz --}}
@php($suggested = app(\Modules\Objektplaene\src\Services\ObjectPlanService::class)->forReport($report))
@if($suggested->isNotEmpty())
    <section class="{{ $class ?? 'mx-auto mt-4 max-w-4xl' }} space-y-2 rounded-2xl border-2 border-amber-300 bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-semibold text-gray-900">Objektpläne zur Einsatzstelle</p>
            <a href="{{ route('objektplaene.index') }}" class="text-xs text-fw-navy underline">Alle Objektpläne</a>
        </div>
        @foreach($suggested as $row)
            @include('objektplaene::partials.object-card', ['object' => $row['object'], 'meters' => $row['meters']])
        @endforeach
    </section>
@endif
