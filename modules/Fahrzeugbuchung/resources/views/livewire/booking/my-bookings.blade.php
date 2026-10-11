<div class="space-y-4">

    {{-- Flash message --}}
    @if(session('success'))
    <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">
        {{ session('success') }}
    </div>
    @endif

    {{-- Filter tabs --}}
    <div class="flex gap-1 p-1 bg-gray-100 rounded-xl w-full sm:w-auto sm:inline-flex">
        @foreach(['upcoming' => 'Kommend', 'past' => 'Vergangen', 'cancelled' => 'Storniert'] as $value => $label)
        <button
            wire:click="$set('filter', '{{ $value }}')"
            class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                   {{ $filter === $value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}"
        >
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- Bookings list --}}
    <div class="space-y-2">
        @forelse($bookings as $booking)
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden flex">

            {{-- Color bar --}}
            <div class="w-1.5 shrink-0" style="background-color: {{ $booking->vehicle->color }}"></div>

            <div class="flex-1 px-4 py-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1 min-w-0">
                        {{-- Vehicle badge + purpose --}}
                        <div class="flex items-center gap-2 flex-wrap">
                            <span
                                class="text-xs font-semibold px-2 py-0.5 rounded-full text-white"
                                style="background-color: {{ $booking->vehicle->color }}"
                            >
                                {{ $booking->vehicle->short_name ?? $booking->vehicle->name }}
                            </span>
                            @if($booking->group)
                            <span class="text-xs text-gray-500">{{ $booking->group->name }}</span>
                            @endif
                            @if($booking->isPending())
                            <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-800">Wartet auf Entscheidung</span>
                            @elseif($booking->isRejected())
                            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-600">Abgelehnt</span>
                            @endif
                        </div>
                        <p class="mt-1 text-sm font-semibold text-gray-900">{{ $booking->purpose }}</p>
                        @if($booking->destination)
                        <p class="text-xs text-gray-500 truncate">{{ $booking->destination }}</p>
                        @endif
                    </div>

                    {{-- Actions --}}
                    @if($filter === 'upcoming')
                    <div class="flex gap-1.5 shrink-0">
                        @can('update', $booking)
                        <button
                            wire:click="editBooking({{ $booking->id }})"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-fw-navy hover:bg-gray-100 transition-colors"
                            title="Bearbeiten"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        @endcan
                        @can('endEarly', $booking)
                        <button
                            wire:click="endEarly({{ $booking->id }})"
                            wire:confirm="Fahrzeug jetzt zurückgeben? Die Buchung endet sofort und das Fahrzeug ist wieder frei."
                            class="inline-flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-medium text-fw-navy hover:bg-gray-100 transition-colors"
                            title="Fahrzeug zurückgeben"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M5 13l4 4L19 7"/>
                            </svg>
                            Zurückgeben
                        </button>
                        @endcan
                        @can('cancel', $booking)
                        <button
                            wire:click="cancelBooking({{ $booking->id }})"
                            wire:confirm="Buchung wirklich stornieren?"
                            class="p-1.5 rounded-lg text-gray-400 hover:text-fw-red hover:bg-red-50 transition-colors"
                            title="Stornieren"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                        @endcan
                    </div>
                    @endif
                </div>

                {{-- Time --}}
                <div class="mt-2 flex items-center gap-1 text-xs text-gray-500">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>
                        {{ $booking->starts_at->setTimezone('Europe/Berlin')->isoFormat('dd., D. MMM YYYY, H:mm') }}
                        –
                        {{ $booking->ends_at->setTimezone('Europe/Berlin')->format('H:i') }}
                        @if(!$booking->starts_at->isSameDay($booking->ends_at))
                            ({{ $booking->ends_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM') }})
                        @endif
                    </span>
                </div>
            </div>
        </div>
        @empty
        <div class="py-12 text-center text-sm text-gray-400">
            @if($filter === 'upcoming') Keine kommenden Buchungen
            @elseif($filter === 'past') Keine vergangenen Buchungen
            @else Keine stornierten Buchungen
            @endif
        </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if($bookings->hasPages())
    <div class="mt-4">
        {{ $bookings->links() }}
    </div>
    @endif

    {{-- BookingForm (edit mode) --}}
    <livewire:booking.booking-form />

</div>
