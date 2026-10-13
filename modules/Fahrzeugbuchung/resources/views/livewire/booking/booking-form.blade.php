<div>
    {{-- Slide-over panel backdrop + panel --}}
    @if($show)
    <div class="fixed inset-0 z-[60] flex justify-end">
        {{-- Backdrop --}}
        <div
            class="absolute inset-0 bg-black/40"
            wire:click="close"
        ></div>

        {{-- Panel --}}
        <div class="relative z-50 w-full max-w-md bg-white shadow-xl flex flex-col h-full overflow-y-auto">

            {{-- Header --}}
            <div class="flex items-center justify-between px-4 py-4 border-b border-gray-200 sticky top-0 bg-white z-10">
                <h2 class="text-lg font-semibold text-gray-900">
                    {{ $bookingId ? 'Buchung bearbeiten' : 'Neue Buchung' }}
                </h2>
                <button wire:click="close" class="p-1 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Form --}}
            <form wire:submit="save" class="flex-1 flex flex-col px-4 py-5 gap-5">

                {{-- Gebucht für (admins only): search instead of a dropdown --}}
                @if($isAdmin)
                <div>
                    <label for="owner_search" class="block text-sm font-medium text-gray-700 mb-1">Gebucht für</label>
                    @if($owner)
                    <div class="flex items-center justify-between rounded-lg bg-gray-50 border border-gray-200 px-3 py-2 mb-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ $owner->name }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $owner->email }}</p>
                        </div>
                        @if($owner->id === auth()->id())
                        <span class="text-xs text-gray-400 shrink-0">du</span>
                        @endif
                    </div>
                    @endif
                    <input
                        id="owner_search"
                        type="search"
                        wire:model.live.debounce.300ms="ownerSearch"
                        placeholder="Andere Person suchen (Name oder E-Mail) …"
                        autocomplete="off"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                    />
                    @if(mb_strlen(trim($ownerSearch)) >= 2)
                    <div class="mt-1 rounded-lg border border-gray-200 divide-y divide-gray-100">
                        @forelse($ownerResults as $result)
                        <button
                            type="button"
                            wire:click="selectOwner({{ $result->id }})"
                            class="w-full text-left px-3 py-2 hover:bg-gray-50"
                        >
                            <span class="block text-sm text-gray-900">{{ $result->name }}</span>
                            <span class="block text-xs text-gray-500">{{ $result->email }}</span>
                        </button>
                        @empty
                        <p class="px-3 py-2 text-sm text-gray-400">Niemand gefunden.</p>
                        @endforelse
                    </div>
                    @endif
                </div>
                @endif

                {{-- Fahrzeug --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fahrzeug *</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach($vehicles as $vehicle)
                        <button
                            type="button"
                            wire:click="$set('vehicleId', {{ $vehicle->id }})"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium border-2 transition-all"
                            @style([
                                "border-color: {$vehicle->color}; background-color: {$vehicle->color}; color: white" => $vehicleId === $vehicle->id,
                                "border-color: {$vehicle->color}; color: #374151; background: white" => $vehicleId !== $vehicle->id,
                            ])
                        >
                            {{ $vehicle->short_name ?? $vehicle->name }}
                        </button>
                        @endforeach
                    </div>
                    @error('vehicleId') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                </div>

                {{-- Availability notice --}}
                @if($blocked)
                <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">
                    <p class="font-semibold">Fahrzeug nicht verfügbar</p>
                    <p class="mt-0.5 text-fw-red">{{ $blocked }}</p>
                    @if(count($alternatives) > 0)
                    <p class="mt-2 font-medium text-red-800">Verfügbare Alternativen:</p>
                    <div class="flex flex-wrap gap-1.5 mt-1">
                        @foreach($alternatives as $alt)
                        <button type="button" wire:click="$set('vehicleId', {{ $alt['id'] }})" class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium text-white" style="background-color: {{ $alt['color'] }}">{{ $alt['name'] }}</button>
                        @endforeach
                    </div>
                    @endif
                </div>
                @endif
                @if(!$available && $conflict)
                <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-sm text-red-800">
                    <p class="font-semibold">Fahrzeug bereits gebucht</p>
                    <p class="mt-0.5 text-fw-red">
                        Von {{ $conflict['userName'] }} ({{ $conflict['startsAt'] }} – {{ $conflict['endsAt'] }})
                        @if($conflict['purpose']) · {{ Str::limit($conflict['purpose'], 40) }} @endif
                    </p>
                    @if(count($alternatives) > 0)
                    <p class="mt-2 font-medium text-red-800">Verfügbare Alternativen:</p>
                    <div class="flex flex-wrap gap-1.5 mt-1">
                        @foreach($alternatives as $alt)
                        <button
                            type="button"
                            wire:click="$set('vehicleId', {{ $alt['id'] }})"
                            class="flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium text-white"
                            style="background-color: {{ $alt['color'] }}"
                        >
                            {{ $alt['name'] }}
                        </button>
                        @endforeach
                    </div>
                    @endif

                    <div class="mt-3 pt-3 border-t border-red-200">
                        <p class="font-medium text-red-800">Fahrzeug trotzdem benötigt?</p>
                        <p class="mt-0.5 text-red-700">
                            Stelle eine Anfrage. Die Wehrführung entscheidet, wer das Fahrzeug bekommt –
                            beide Seiten werden per E-Mail informiert.
                            @if($bookingId) Bis zur Entscheidung bleibt deine bisherige Buchung unverändert bestehen. @endif
                        </p>
                        <label for="reason" class="block mt-2 text-sm font-medium text-red-800">Begründung *</label>
                        <textarea
                            id="reason"
                            wire:model="reason"
                            rows="2"
                            placeholder="z. B. Einsatzübung mit der Nachbarwehr, nur mit diesem Fahrzeug möglich"
                            class="mt-1 w-full rounded-lg border border-red-200 bg-white px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"
                        ></textarea>
                        @error('reason') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                    </div>
                </div>
                @endif

                {{-- Zeitraum --}}
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="starts_at" class="block text-sm font-medium text-gray-700 mb-1">Von *</label>
                        <input
                            id="starts_at"
                            type="datetime-local"
                            wire:model.live.debounce.500ms="startsAt"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                        />
                        @error('startsAt') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="ends_at" class="block text-sm font-medium text-gray-700 mb-1">Bis *</label>
                        <input
                            id="ends_at"
                            type="datetime-local"
                            wire:model.live.debounce.500ms="endsAt"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                        />
                        @error('endsAt') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Zweck --}}
                <div>
                    <label for="purpose" class="block text-sm font-medium text-gray-700 mb-1">Zweck *</label>
                    <input
                        id="purpose"
                        type="text"
                        wire:model="purpose"
                        placeholder="z. B. Lehrgang"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                    />
                    @error('purpose') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                </div>

                {{-- Ziel --}}
                <div>
                    <label for="destination" class="block text-sm font-medium text-gray-700 mb-1">Ziel / Ort</label>
                    <input
                        id="destination"
                        type="text"
                        wire:model="destination"
                        placeholder="z. B. Feuerwehrschule"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                    />
                </div>

                {{-- Abteilung --}}
                @if($groups->isNotEmpty())
                <div>
                    <label for="group_id" class="block text-sm font-medium text-gray-700 mb-1">Abteilung (optional)</label>
                    <select
                        id="group_id"
                        wire:model="groupId"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent"
                    >
                        <option value="">– keine –</option>
                        @foreach($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Notizen --}}
                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notizen</label>
                    <textarea
                        id="notes"
                        wire:model="notes"
                        rows="3"
                        placeholder="Weitere Infos für Mitglieder …"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"
                    ></textarea>
                    @error('notes') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
                </div>

                {{-- Submit --}}
                <div class="mt-auto pt-3 border-t border-gray-100">
                    @php($requestMode = !$available && $conflict !== null)
                    <button
                        type="submit"
                        class="w-full py-2.5 px-4 rounded-xl font-semibold text-white text-sm transition-colors
                               {{ $available || $requestMode ? 'bg-fw-red hover:bg-fw-red-dark' : 'bg-gray-400 cursor-not-allowed' }}"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ $requestMode ? 'Entscheidung anfragen' : ($bookingId ? 'Speichern' : 'Buchen') }}
                        </span>
                        <span wire:loading wire:target="save">Wird gesendet …</span>
                    </button>
                </div>

            </form>
        </div>
    </div>
    @endif
</div>
