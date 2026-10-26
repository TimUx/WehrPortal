<div>
    {{-- Modal backdrop + dialog --}}
    <div
        class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4"
        @close-detail-modal.window="$wire.$parent.closeDetailModal()"
    >
        {{-- Backdrop --}}
        <div
            class="absolute inset-0 bg-black/40"
            wire:click="$parent.closeDetailModal()"
        ></div>

        {{-- Sheet / dialog --}}
        <div class="relative z-50 w-full sm:max-w-md max-h-[90vh] overflow-y-auto bg-white rounded-t-2xl sm:rounded-2xl shadow-xl">

            {{-- Colored top bar --}}
            <div
                class="h-1.5 w-full"
                style="background-color: {{ $booking->vehicle->color }}"
            ></div>

            {{-- Header --}}
            <div class="flex items-start justify-between px-4 pt-4 pb-3">
                <div>
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold text-white"
                        style="background-color: {{ $booking->vehicle->color }}"
                    >
                        {{ $booking->vehicle->short_name ?? $booking->vehicle->name }}
                    </span>
                    @if($booking->isCancelled())
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                        Storniert
                    </span>
                    @elseif($booking->isPending())
                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                        Wartet auf Entscheidung
                    </span>
                    @endif
                </div>
                <button
                    wire:click="$parent.closeDetailModal()"
                    class="p-1 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Content --}}
            <div class="px-4 pb-5 space-y-3">

                {{-- Zweck --}}
                <div>
                    <p class="text-lg font-semibold text-gray-900">{{ $booking->purpose }}</p>
                    @if($booking->destination)
                    <p class="text-sm text-gray-500 mt-0.5">
                        <svg class="inline w-4 h-4 -mt-0.5 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        {{ $booking->destination }}
                    </p>
                    @endif
                </div>

                {{-- Zeitraum --}}
                <div class="flex items-start gap-2 text-sm text-gray-700">
                    <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <div>
                        <span class="font-medium">{{ $booking->starts_at->setTimezone('Europe/Berlin')->isoFormat('dd., D. MMM YYYY') }}</span>
                        <span class="text-gray-500">
                            {{ $booking->starts_at->setTimezone('Europe/Berlin')->format('H:i') }}
                            –
                            {{ $booking->ends_at->setTimezone('Europe/Berlin')->format('H:i') }}
                            @if(!$booking->starts_at->isSameDay($booking->ends_at))
                                ({{ $booking->ends_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM') }})
                            @endif
                        </span>
                    </div>
                </div>

                {{-- Nutzer / Abteilung --}}
                <div class="flex items-center gap-2 text-sm text-gray-700">
                    <x-avatar :user="$booking->user" size="h-6 w-6" text="text-[10px]" />
                    <span>
                        {{ $booking->user->name }}
                        @if($booking->group)
                            · <span class="text-gray-500">{{ $booking->group->display_name }}</span>
                        @endif
                    </span>
                </div>

                {{-- Notizen --}}
                @if($booking->notes)
                <div class="rounded-lg bg-gray-50 px-3 py-2.5 text-sm text-gray-700">
                    <p class="text-xs font-medium text-gray-500 mb-1">Notiz</p>
                    {{ $booking->notes }}
                </div>
                @endif

                @if($booking->isPending() && $booking->decision && auth()->user()->canDecide())
                <a href="{{ route('fahrzeugbuchung.decisions.show', $booking->decision) }}"
                   class="block text-center py-2 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white">
                    Zur Entscheidung
                </a>
                @endif

                {{-- Actions --}}
                @if(!$booking->isCancelled())
                <div class="flex gap-2 pt-2">

                    @if($canEdit)
                    <button
                        wire:click="editBooking"
                        class="flex-1 py-2 px-4 rounded-xl border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                        Bearbeiten
                    </button>
                    @endif

                    @if($canEndEarly)
                    @if($confirmEndEarly)
                    <button
                        wire:click="endEarly"
                        class="flex-1 py-2 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-medium text-white transition-colors"
                    >
                        <span wire:loading.remove wire:target="endEarly">Jetzt zurückgeben?</span>
                        <span wire:loading wire:target="endEarly">Wird zurückgegeben …</span>
                    </button>
                    @else
                    <button
                        wire:click="$set('confirmEndEarly', true)"
                        class="flex-1 py-2 px-4 rounded-xl border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    >
                        Fahrzeug zurückgeben
                    </button>
                    @endif
                    @endif

                    @if($canCancel)
                    @if($confirmCancel)
                    <button
                        wire:click="cancel"
                        class="flex-1 py-2 px-4 rounded-xl bg-fw-red hover:bg-fw-red text-sm font-medium text-white transition-colors"
                    >
                        <span wire:loading.remove wire:target="cancel">Wirklich stornieren?</span>
                        <span wire:loading wire:target="cancel">Wird storniert …</span>
                    </button>
                    @else
                    <button
                        wire:click="$set('confirmCancel', true)"
                        class="flex-1 py-2 px-4 rounded-xl border border-red-200 text-sm font-medium text-fw-red hover:bg-red-50 transition-colors"
                    >
                        {{ $booking->isPending() ? 'Anfrage zurückziehen' : 'Stornieren' }}
                    </button>
                    @endif
                    @endif

                </div>
                @endif

                <livewire:booking.booking-comments :bookingId="$booking->id" :key="'comments-'.$booking->id" />

            </div>
        </div>
    </div>
</div>
