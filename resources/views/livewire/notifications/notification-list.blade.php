<div class="mx-auto max-w-2xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Benachrichtigungen</h1>
            <p class="text-sm text-gray-500">Was dir auch per E-Mail geschickt wurde. Ein Klick führt dorthin, wo etwas zu tun ist. Einträge bleiben {{ \App\Models\UserNotification::KEEP_DAYS }} Tage.</p>
        </div>
        <div class="flex items-center gap-4 text-sm">
            <label class="flex items-center gap-2 text-gray-700"><input type="checkbox" wire:model.live="onlyUnread" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Nur ungelesene</label>
            @if($unread && $viewingAs)<span class="text-gray-500">Als Kamerad angesehen – bleibt ungelesen</span>
            @elseif($unread)<button type="button" wire:click="markAllRead" class="text-fw-navy underline">Alle als gelesen markieren</button>@endif
        </div>
    </div>

    <div class="divide-y divide-gray-100 overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($notifications as $n)
            <div wire:key="n-{{ $n->id }}">@include('livewire.notifications.item', ['n' => $n])</div>
        @empty
            <p class="px-4 py-6 text-center text-sm text-gray-500">{{ $onlyUnread ? 'Keine ungelesenen Benachrichtigungen.' : 'Noch keine Benachrichtigungen.' }}</p>
        @endforelse
    </div>

    {{ $notifications->links() }}
</div>
