{{-- Start page: the next birthday among the Kameraden, day and month only --}}
@php($first = $birthdays->first())
<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nächster Geburtstag</p>
    <p class="mt-1 font-semibold text-gray-900">{{ $birthdays->map(fn ($b) => $b['user']->name)->implode(', ') }}</p>
    <p class="mt-1 text-sm {{ $first['days'] === 0 ? 'font-semibold text-fw-red' : 'text-gray-600' }}">
        {{ $first['days'] === 0 ? 'Heute – herzlichen Glückwunsch!' : ($first['days'] === 1 ? 'Morgen, '.$first['date']->format('d.m.') : $first['date']->format('d.m.').' · in '.$first['days'].' Tagen') }}
    </p>
</div>
