{{-- One entry: icon of the module, title, first line, time; a dot while unread --}}
<a href="{{ route('notifications.open', $n->id) }}" class="flex items-start gap-3 px-4 py-3 hover:bg-gray-50 {{ $n->read_at ? '' : 'bg-red-50/40' }}">
    <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-fw-navy/10 text-fw-navy">
        @if($n->data['module'] ?? null)
            <x-module-icon :key="$n->data['module']" class="h-4 w-4" />
        @else
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 0 0-4-5.7V5a2 2 0 1 0-4 0v.3A6 6 0 0 0 6 11v3.2a2 2 0 0 1-.6 1.4L4 17h5m6 0v1a3 3 0 1 1-6 0v-1m6 0H9"/></svg>
        @endif
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-sm {{ $n->read_at ? 'text-gray-700' : 'font-semibold text-gray-900' }}">{{ $n->data['title'] ?? 'Benachrichtigung' }}</span>
        @if(filled($n->data['body'] ?? null))<span class="block truncate text-xs text-gray-500">{{ $n->data['body'] }}</span>@endif
        <span class="block text-xs text-gray-400">{{ $n->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
    </span>
    @unless($n->read_at)<span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-fw-red" aria-label="ungelesen"></span>@endunless
</a>
