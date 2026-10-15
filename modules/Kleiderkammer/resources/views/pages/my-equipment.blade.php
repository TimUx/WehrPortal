<x-layouts.app title="Meine Ausrüstung">

    <h1 class="text-xl font-bold text-gray-900 mb-4">Meine Ausrüstung</h1>

    @if($items->isEmpty())
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 text-sm text-gray-600">
            Dir ist zurzeit keine Ausrüstung ausgegeben.
        </div>
    @else
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
            @foreach($items as $issued)
                @php($type = $issued->item?->itemType ?? $issued->itemType)
                @php($size = $issued->item?->size ?? $issued->size)
                @php($group = $issued->item?->group ?? $issued->group)
                <div class="p-4" x-data="{ open: false }">
                    <button type="button" class="flex w-full items-start justify-between gap-4 text-left"
                            x-on:click="open = ! open" x-bind:aria-expanded="open">
                        <span class="min-w-0">
                            <span class="block font-semibold text-gray-900">
                                {{ $type?->name ?? 'Unbekannter Artikel' }}
                                @if($issued->quantity > 1)<span class="text-gray-500 font-normal">× {{ $issued->quantity }}</span>@endif
                            </span>
                            <span class="block text-sm text-gray-500 mt-0.5">
                                {{ collect([$group?->name, $size ? 'Größe '.$size->name : null])->filter()->implode(' · ') }}
                                @if(($size || $group) && $issued->item) · @endif
                                @if($issued->item)<span class="font-mono">{{ $issued->item->code }}</span>@endif
                            </span>
                        </span>
                        <span class="text-right text-sm text-gray-500 whitespace-nowrap">
                            seit {{ $issued->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}
                            <span class="block text-xs text-fw-red">Mängel melden</span>
                        </span>
                    </button>
                    <div x-show="open" x-cloak>
                        <livewire:kleiderkammer.equipment-message-form :issued-item-id="$issued->id" :key="'msg-'.$issued->id" />
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</x-layouts.app>
