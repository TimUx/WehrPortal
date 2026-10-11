<a href="{{ $polls->count() === 1 ? route('kalender.polls.show', $polls->first()) : route('kalender.polls') }}" class="block bg-white rounded-2xl border border-amber-200 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Terminabfrage</p>
    @foreach($polls->take(3) as $poll)
        <p class="mt-1 text-sm font-semibold text-gray-900">Wann passt es dir? {{ $poll->title }}</p>
    @endforeach
    <p class="text-sm text-fw-navy underline">Termine ankreuzen</p>
</a>
