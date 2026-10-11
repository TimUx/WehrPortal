<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">{{ $thread->subject }}</h1>
        <p class="text-sm text-gray-500">{{ $thread->isClosed() ? 'Erledigt' : 'Offen' }}</p>
    </div>

    @if(session('success'))
    <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    <div class="space-y-3">
        @foreach($messages as $message)
        <div class="flex {{ $message->from_admin ? 'justify-start' : 'justify-end' }}">
            <div class="max-w-[85%] rounded-2xl px-4 py-2.5 {{ $message->from_admin ? 'bg-white border border-gray-200' : 'bg-fw-navy text-white' }}">
                <p class="text-xs {{ $message->from_admin ? 'text-gray-500' : 'text-white/70' }}">
                    {{ $message->from_admin ? ($message->author?->name ?? 'Admin').' (Admin)' : 'Du' }}
                    · {{ $message->created_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM, HH:mm') }}
                </p>
                <p class="mt-1 text-sm whitespace-pre-line">{{ $message->body }}</p>
            </div>
        </div>
        @endforeach
    </div>

    <form wire:submit="reply" class="rounded-2xl border border-gray-200 bg-white p-4 space-y-3">
        <label for="reply_body" class="block text-sm font-medium text-gray-700">Antworten</label>
        <textarea id="reply_body" wire:model="body" rows="3"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"></textarea>
        @error('body') <p class="text-sm text-fw-red">{{ $message }}</p> @enderror
        <button type="submit" class="w-full py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white">
            <span wire:loading.remove wire:target="reply">Senden</span>
            <span wire:loading wire:target="reply">Wird gesendet …</span>
        </button>
        @if($thread->isClosed())
        <p class="text-xs text-gray-500">Dieses Gespräch ist als erledigt markiert. Mit einer Antwort öffnest du es wieder.</p>
        @endif
    </form>
</div>
