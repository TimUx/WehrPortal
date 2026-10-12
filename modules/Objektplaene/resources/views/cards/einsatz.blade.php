{{-- Start page, on top: a running Einsatz at an object with plans --}}
<div class="space-y-3 rounded-2xl border-2 border-fw-red bg-white p-4 shadow-sm">
    @foreach($running as $row)
        <p class="text-sm font-semibold text-fw-red">Einsatz {{ $row['report']->keyword ?: $row['report']->number }}@if($row['report']->address) · {{ $row['report']->address }}@endif – Objektpläne</p>
        @foreach($row['objects'] as $hit)
            @include('objektplaene::partials.object-card', ['object' => $hit['object'], 'meters' => $hit['meters']])
        @endforeach
    @endforeach
</div>
