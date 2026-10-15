<div class="space-y-4">
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('issue')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900">Meine Schlüssel</h2>
        @forelse($held as $h)
            <div class="flex flex-wrap items-start justify-between gap-2 border-t border-gray-100 pt-3 first:border-0 first:pt-0" wire:key="h-{{ $h->id }}">
                <div>
                    <p class="font-medium text-gray-900">{{ $h->key->name }} <span class="font-normal text-gray-500">· {{ $h->key->code }}</span></p>
                    <p class="text-xs text-gray-500">{{ $h->key->kindLabel() }}@if($h->key->opensLabel()) · {{ $h->key->opensLabel() }}@endif · seit {{ $h->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}</p>
                    @if($h->due_on)<p class="text-xs {{ $h->overdue() ? 'font-semibold text-red-700' : 'text-gray-600' }}">Rückgabe bis {{ $h->due_on->format('d.m.Y') }}{{ $h->overdue() ? ' – überfällig' : '' }}</p>@endif
                    @if($h->key->status === 'lost')<p class="text-xs font-semibold text-red-700">Als verloren gemeldet</p>@endif
                </div>
                @if($h->key->status !== 'lost')
                    <button type="button" wire:click="reportLost({{ $h->id }})" wire:confirm="„{{ $h->key->name }}“ als verloren melden? Der Schlüsselwart wird informiert." class="text-sm text-red-600 underline">Verloren melden</button>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Du hast keine Schlüssel der Wehr.</p>
        @endforelse
    </div>
</div>
