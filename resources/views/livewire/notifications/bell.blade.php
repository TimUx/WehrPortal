<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" wire:poll.60s>
    <button type="button" x-on:click="open = ! open" class="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-white/10"
            aria-label="Benachrichtigungen{{ $unread ? ' ('.$unread.' ungelesen)' : '' }}" title="Benachrichtigungen">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9"/></svg>
        @if($unread)
            <span class="absolute -right-0.5 -top-0.5 min-w-4 h-4 rounded-full bg-fw-red px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity x-on:click.outside="open = false"
         class="fixed inset-x-2 top-12 z-50 overflow-hidden rounded-2xl border border-gray-100 bg-white text-gray-900 shadow-xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-11 sm:w-96">
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
            <p class="text-sm font-semibold">Benachrichtigungen</p>
            @if($unread)<button type="button" wire:click="markAllRead" class="text-xs text-fw-navy underline">Alle als gelesen markieren</button>@endif
        </div>
        <div class="max-h-[60vh] divide-y divide-gray-100 overflow-y-auto">
            @forelse($latest as $n)
                @include('livewire.notifications.item', ['n' => $n])
            @empty
                <p class="px-4 py-6 text-center text-sm text-gray-500">Keine Benachrichtigungen.</p>
            @endforelse
        </div>
        <a href="{{ route('notifications.index') }}" class="block border-t border-gray-100 px-4 py-2.5 text-center text-sm font-medium text-fw-navy hover:bg-gray-50">Alle anzeigen</a>
    </div>
</div>
