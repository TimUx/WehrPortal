<div class="rounded-2xl border border-gray-200 bg-white p-5 space-y-3">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold text-gray-900">Feedback an die Admins</h2>
        @unless($showForm)
        <button wire:click="$set('showForm', true)" class="text-sm font-medium text-fw-red hover:text-fw-red-dark">
            Neues Feedback
        </button>
        @endunless
    </div>

    @if($showForm)
    <form wire:submit="send" class="space-y-3">
        @if(count($modules) > 1)
        <div>
            <label for="feedback_module" class="block text-sm font-medium text-gray-700 mb-1">Bereich</label>
            <select id="feedback_module" wire:model="moduleKey"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent">
                <option value="">Allgemein</option>
                @foreach($modules as $key => $def)
                <option value="{{ $key }}">{{ $def['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div>
            <label for="feedback_subject" class="block text-sm font-medium text-gray-700 mb-1">Betreff *</label>
            <input id="feedback_subject" type="text" wire:model="subject" placeholder="z. B. Idee für den Kalender"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent">
            @error('subject') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="feedback_body" class="block text-sm font-medium text-gray-700 mb-1">Nachricht *</label>
            <textarea id="feedback_body" wire:model="body" rows="4" placeholder="Was funktioniert gut, was nicht, was fehlt?"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-fw-navy focus:border-transparent resize-none"></textarea>
            @error('body') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="flex-1 py-2.5 px-4 rounded-xl bg-fw-red hover:bg-fw-red-dark text-sm font-semibold text-white">
                <span wire:loading.remove wire:target="send">Senden</span>
                <span wire:loading wire:target="send">Wird gesendet …</span>
            </button>
            <button type="button" wire:click="$set('showForm', false)" class="py-2.5 px-4 rounded-xl border border-gray-300 text-sm text-gray-700 hover:bg-gray-50">
                Abbrechen
            </button>
        </div>
    </form>
    @endif

    @if($threads->isNotEmpty())
    <div class="divide-y divide-gray-100 -mx-1">
        @foreach($threads as $thread)
        <a href="{{ route('feedback.show', $thread) }}" class="flex items-center justify-between gap-3 px-1 py-2.5 hover:bg-gray-50 rounded-lg">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ $thread->subject }}</p>
                <p class="text-xs text-gray-500">
                    {{ $thread->last_message_at?->setTimezone('Europe/Berlin')->isoFormat('D. MMM YYYY, HH:mm') }}
                    · {{ $thread->isClosed() ? 'Erledigt' : 'Offen' }}
                </p>
            </div>
            @if($thread->unread_for_user)
            <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full bg-fw-red text-white">Neue Antwort</span>
            @endif
        </a>
        @endforeach
    </div>
    @elseif(!$showForm)
    <p class="text-sm text-gray-500">Du hast eine Idee oder ein Problem? Schreib den Admins – die Antwort siehst du hier.</p>
    @endif
</div>
