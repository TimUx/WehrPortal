@props(['booking', 'label', 'highlight' => false])
<div class="rounded-xl border {{ $highlight ? 'border-fw-red' : 'border-gray-200' }} bg-white overflow-hidden flex">
    <div class="w-1.5 shrink-0" style="background-color: {{ $booking->vehicle->color }}"></div>
    <div class="flex-1 px-4 py-3 space-y-1">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">{{ $label }}</p>
        <div class="flex items-center gap-2 flex-wrap">
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full text-white" style="background-color: {{ $booking->vehicle->color }}">
                {{ $booking->vehicle->displayName() }}
            </span>
            @if($booking->isCancelled())
            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Storniert</span>
            @elseif($booking->isRejected())
            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Abgelehnt</span>
            @elseif($booking->isPending())
            <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">Wartet auf Entscheidung</span>
            @endif
        </div>
        <p class="text-sm font-semibold text-gray-900">{{ $booking->purpose }}</p>
        <p class="text-sm text-gray-600">
            {{ $booking->starts_at->setTimezone('Europe/Berlin')->isoFormat('dd., D. MMM YYYY, HH:mm') }}
            – {{ $booking->ends_at->setTimezone('Europe/Berlin')->isSameDay($booking->starts_at->setTimezone('Europe/Berlin'))
                ? $booking->ends_at->setTimezone('Europe/Berlin')->format('H:i')
                : $booking->ends_at->setTimezone('Europe/Berlin')->isoFormat('dd., D. MMM, HH:mm') }} Uhr
        </p>
        <p class="text-sm text-gray-600">
            {{ $booking->user->name }}
            @if(\App\Support\MemberFields::PHONE && $booking->user->phone) · <a href="tel:{{ $booking->user->phone }}" class="underline">{{ $booking->user->phone }}</a>@endif
            @if($booking->group) · {{ $booking->group->name }}@endif
        </p>
        @if($booking->destination)
        <p class="text-xs text-gray-500">Ziel: {{ $booking->destination }}</p>
        @endif
        {{ $slot }}
    </div>
</div>
