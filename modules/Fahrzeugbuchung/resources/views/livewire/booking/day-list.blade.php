<div>
    {{-- Bottom sheet modal --}}
    <div
        class="fixed inset-0 z-[60] flex items-end justify-center"
        @close-day-list.window="$wire.$parent.closeDayList()"
    >
        {{-- Backdrop --}}
        <div
            class="absolute inset-0 bg-black/40"
            wire:click="$parent.closeDayList()"
        ></div>

        {{-- Sheet --}}
        <div class="relative z-50 w-full bg-white rounded-t-2xl shadow-xl max-h-[80vh] flex flex-col">

            {{-- Handle --}}
            <div class="flex justify-center pt-3 pb-1">
                <div class="w-10 h-1 rounded-full bg-gray-300"></div>
            </div>

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
                <h2 class="text-base font-semibold text-gray-900">
                    {{ $day->isoFormat('dddd, D. MMMM YYYY') }}
                </h2>
                <button
                    wire:click="$parent.closeDayList()"
                    class="p-1 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- List --}}
            <div class="overflow-y-auto flex-1 px-4 py-3 space-y-2">
                @forelse($bookings as $booking)
                <button
                    wire:click="openDetail({{ $booking->id }})"
                    class="w-full text-left rounded-xl p-3 hover:bg-gray-50 transition-colors border border-gray-100 flex items-start gap-3"
                >
                    {{-- Color bar --}}
                    <div
                        class="w-1 self-stretch rounded-full shrink-0"
                        style="background-color: {{ $booking->vehicle->color }}"
                    ></div>

                    <div class="flex-1 min-w-0">
                        {{-- Vehicle + time --}}
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="text-xs font-semibold px-2 py-0.5 rounded-full text-white shrink-0"
                                style="background-color: {{ $booking->vehicle->color }}"
                            >
                                {{ $booking->vehicle->short_name ?? $booking->vehicle->name }}
                            </span>
                            <span class="text-xs text-gray-500 shrink-0">
                                {{ $booking->starts_at->setTimezone('Europe/Berlin')->format('H:i') }}
                                –
                                {{ $booking->ends_at->setTimezone('Europe/Berlin')->format('H:i') }}
                            </span>
                        </div>
                        {{-- Purpose --}}
                        <p class="mt-1 text-sm font-medium text-gray-900 truncate">
                            @if($booking->isPending())<span class="mr-1 px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-xs font-medium">Angefragt</span>@endif
                            {{ $booking->purpose }}
                        </p>
                        <p class="text-xs text-gray-500">{{ $booking->user->name }}</p>
                    </div>
                </button>
                @empty
                <p class="py-8 text-center text-sm text-gray-400">Keine Buchungen an diesem Tag</p>
                @endforelse
            </div>

            {{-- New booking button --}}
            <div class="px-4 py-4 border-t border-gray-100 safe-area-bottom">
                <button
                    wire:click="openNewBooking"
                    class="w-full py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark font-semibold text-white text-sm transition-colors"
                >
                    + Neue Buchung für diesen Tag
                </button>
            </div>

        </div>
    </div>
</div>
