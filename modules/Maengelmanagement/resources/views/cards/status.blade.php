<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 space-y-1">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mängel</p>
    @foreach($questions as $d)
        <p class="text-sm font-semibold text-amber-800"><a href="{{ route('maengelmanagement.show', $d) }}" class="underline">Rückfrage zu „{{ $d->title }}“</a> – bitte antworten</p>
    @endforeach
    @if($assigned)<p class="text-sm"><a href="{{ route('maengelmanagement.index', ['ansicht' => 'mir']) }}" class="underline">{{ $assigned }} {{ $assigned === 1 ? 'Mangel' : 'Mängel' }} dir zugewiesen</a></p>@endif
    @if($own)<p class="text-sm text-gray-700"><a href="{{ route('maengelmanagement.index', ['ansicht' => 'meine']) }}" class="underline">{{ $own }} deiner Meldungen offen</a></p>@endif
    @if($manager)
        <p class="text-sm text-gray-700">{{ $open }} offen{{ $critical ? ', ' : '' }}@if($critical)<span class="font-semibold text-red-700">{{ $critical }} kritisch</span>@endif{{ $unassigned ? ', '.$unassigned.' ohne Zuständigen' : '' }}</p>
    @endif
    <a href="{{ route('maengelmanagement.report') }}" class="text-sm text-fw-navy underline">Mangel melden</a>
</div>
