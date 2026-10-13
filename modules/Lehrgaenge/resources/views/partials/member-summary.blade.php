{{-- Absolvierte and coming Lehrgänge of $member, e.g. in the Personalakte and the Personalverwaltung --}}
@php
    $lqRecords = \Modules\Lehrgaenge\src\Models\Record::with(['course', 'sessions'])->where('user_id', $member->id)->whereIn('status', ['completed', 'confirmed'])->get();
    $lqDone = $lqRecords->where('status', 'completed')->sortByDesc(fn ($r) => $r->completed_on?->timestamp ?? 0);
    $lqComing = $lqRecords->where('status', 'confirmed')->sortBy(fn ($r) => $r->starts_on?->timestamp ?? PHP_INT_MAX);
@endphp
<section class="{{ $class ?? 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm' }}">
    <h2 class="text-sm font-semibold text-gray-900">Lehrgänge und Qualifikationen</h2>
    <ul class="divide-y divide-gray-100 text-sm">
        @forelse($lqDone as $r)
            <li class="flex flex-wrap justify-between gap-2 py-1.5">
                <span class="font-medium text-gray-900">{{ $r->course->name }}</span>
                <span class="text-xs text-gray-500">absolviert {{ $r->completed_on?->format('d.m.Y') }}@if($r->validUntil())<span class="{{ ['expired' => 'font-semibold text-red-700', 'soon' => 'text-amber-700'][$r->refreshState()] ?? '' }}"> · gültig bis {{ $r->validUntil()->format('d.m.Y') }}</span>@endif</span>
            </li>
        @empty
            <li class="py-1.5 text-gray-500">Noch keine absolvierten Lehrgänge eingetragen.</li>
        @endforelse
    </ul>
    @if($lqComing->isNotEmpty())
        <p class="text-xs font-semibold text-gray-700">Angemeldet</p>
        <ul class="divide-y divide-gray-100 text-sm">
            @foreach($lqComing as $r)
                <li class="py-1.5"><span class="font-medium text-gray-900">{{ $r->course->name }}</span>
                    @foreach($r->sessions as $s)<span class="block text-xs text-gray-600">{{ $s->label() }}</span>@endforeach</li>
            @endforeach
        </ul>
    @endif
</section>
