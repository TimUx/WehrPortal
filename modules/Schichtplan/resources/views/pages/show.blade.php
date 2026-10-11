@php
    $plan->load(['stations.lead', 'slots']);
    $assignments = $plan->assignments()->with('user')->get();
    $me = auth()->id();
    $mine = $assignments->where('user_id', $me)->sortBy(fn ($a) => $plan->slots->firstWhere('id', $a->slot_id)->starts_at);
    $leading = $plan->stations->where('lead_user_id', $me);
@endphp
<x-layouts.app :title="$plan->title">
    <div class="space-y-4">
        <div>
            <a href="{{ route('schichtplan.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Schichtplan</a>
            <div class="mt-1 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">{{ $plan->title }}</h1>
                    <p class="text-sm text-gray-500">{{ $plan->timeLabel() }}@if($plan->location) · {{ $plan->location }}@endif</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('schichtplan.print', $plan) }}" target="_blank" rel="noopener" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">Druckansicht</a>
                    <a href="{{ route('schichtplan.csv', $plan) }}" class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">CSV</a>
                    @can('manage', $plan)
                        <a href="{{ route('schichtplan.edit', $plan) }}" class="inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">Bearbeiten</a>
                    @endcan
                </div>
            </div>
        </div>

        {{-- Your part: shifts with who works alongside, and Stationsverantwortung --}}
        @if($mine->isNotEmpty() || $leading->isNotEmpty())
            <div class="rounded-2xl bg-red-50 border border-red-100 p-4 space-y-2">
                @if($leading->isNotEmpty())
                    <p class="text-sm font-semibold text-red-900">Du hast die Stationsverantwortung für {{ $leading->pluck('name')->implode(', ') }} – den ganzen Abend.</p>
                @endif
                @if($mine->isNotEmpty())
                    <p class="text-sm font-semibold text-red-900">Deine Schichten</p>
                    <ul class="space-y-1 text-sm text-red-900">
                        @foreach($mine as $a)
                            @php($station = $plan->stations->firstWhere('id', $a->station_id))
                            @php($others = $assignments->where('slot_id', $a->slot_id)->where('station_id', $a->station_id)->where('user_id', '!=', $me)->pluck('user.name'))
                            <li>
                                <span class="font-semibold">{{ $plan->slots->firstWhere('id', $a->slot_id)->label() }} Uhr</span> · {{ $station->name }}
                                @if($others->isNotEmpty())<span class="text-red-800/80"> mit {{ $others->implode(', ') }}</span>@endif
                                @if($station->lead && $station->lead_user_id !== $me)<span class="text-red-800/80"> · verantwortlich: {{ $station->lead->name }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        {{-- The whole plan; your own places stand out --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 overflow-x-auto">
            <h2 class="text-sm font-semibold text-gray-900 mb-2">Gesamter Schichtplan</h2>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="sticky left-0 bg-white py-2 pr-3">Zeit</th>
                        @foreach($plan->stations as $station)
                            <th class="py-2 pr-3">
                                {{ $station->name }}
                                @if($station->lead)<span class="block font-normal normal-case {{ $station->lead_user_id === $me ? 'font-semibold text-fw-red' : 'text-fw-navy' }}">Verantw.: {{ $station->lead->name }}</span>@endif
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($plan->slots as $slot)
                        @php($myRow = $mine->contains('slot_id', $slot->id))
                        <tr class="{{ $myRow ? 'bg-red-50/40' : '' }}">
                            <td class="sticky left-0 py-2 pr-3 whitespace-nowrap font-medium {{ $myRow ? 'bg-red-50 text-fw-red' : 'bg-white text-gray-900' }}">{{ $slot->label() }}</td>
                            @foreach($plan->stations as $station)
                                @php($seat = $assignments->where('slot_id', $slot->id)->where('station_id', $station->id))
                                @php($isMine = $seat->contains('user_id', $me))
                                <td class="py-2 pr-3 align-top">
                                    <div class="{{ $isMine ? 'rounded-lg bg-fw-red/10 ring-2 ring-fw-red px-2 py-1' : '' }}">
                                        @forelse($seat as $a)
                                            <div class="{{ $a->user_id === $me ? 'font-bold text-fw-red' : 'text-gray-800' }}">{{ $a->user->name }}</div>
                                        @empty
                                            <div class="text-gray-300">–</div>
                                        @endforelse
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if($mine->isNotEmpty())<p class="mt-2 text-xs text-gray-500">Deine Schichten sind rot umrandet.</p>@endif
        </div>
    </div>
</x-layouts.app>
