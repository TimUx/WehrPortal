<div class="border-t border-gray-100 pt-3">
    <p class="text-xs font-medium text-gray-500 mb-2">
        Nachrichten
        @if($comments->isNotEmpty())
        <span class="text-gray-400">({{ $comments->whereNull('deleted_at')->count() }})</span>
        @endif
    </p>

    <div class="space-y-2.5 max-h-72 overflow-y-auto pr-1">
        @forelse($comments as $comment)
        @php($own = $comment->user_id === auth()->id())
        <div wire:key="comment-{{ $comment->id }}" class="flex items-start gap-2">
            <x-avatar :user="$comment->user" size="h-6 w-6" text="text-[10px]" class="mt-0.5" />

            <div class="flex-1 min-w-0">
                <div class="flex items-baseline gap-1.5 flex-wrap text-xs">
                    <span class="font-semibold text-gray-800">{{ $comment->user->name }}</span>
                    <span class="text-gray-400">{{ $comment->created_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM, HH:mm') }}</span>
                    @if($comment->trashed())
                    <span class="px-1.5 py-0.5 rounded bg-red-50 text-fw-red font-medium">
                        gelöscht {{ $comment->deleted_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM, HH:mm') }}
                    </span>
                    @elseif($comment->isEdited())
                    <span class="text-gray-400 italic">bearbeitet</span>
                    @endif
                </div>

                @if($editingId === $comment->id)
                <form wire:submit="saveEdit" class="mt-1 space-y-1.5">
                    <textarea wire:model="editBody" rows="2"
                              class="w-full rounded-lg border-gray-300 text-sm focus:border-fw-navy focus:ring-fw-navy"></textarea>
                    @error('editBody') <p class="text-xs text-fw-red">{{ $message }}</p> @enderror
                    <div class="flex gap-2">
                        <button type="submit" class="px-3 py-1 rounded-lg bg-fw-red hover:bg-fw-red-dark text-xs font-medium text-white">Speichern</button>
                        <button type="button" wire:click="cancelEdit" class="px-3 py-1 rounded-lg border border-gray-300 text-xs text-gray-700">Abbrechen</button>
                    </div>
                </form>
                @else
                <p class="mt-0.5 text-sm whitespace-pre-line break-words {{ $comment->trashed() ? 'text-gray-400 line-through' : 'text-gray-700' }}">{{ $comment->body }}</p>

                @if($seesHistory && $comment->revisions->isNotEmpty())
                <details class="mt-1 text-xs">
                    <summary class="cursor-pointer text-gray-400 hover:text-gray-600">Frühere Fassungen ({{ $comment->revisions->count() }})</summary>
                    <ul class="mt-1 space-y-1 border-l-2 border-gray-100 pl-2">
                        @foreach($comment->revisions as $revision)
                        <li>
                            <span class="text-gray-400">bis {{ $revision->created_at->setTimezone('Europe/Berlin')->isoFormat('D. MMM, HH:mm') }}:</span>
                            <span class="text-gray-600 whitespace-pre-line break-words">{{ $revision->body }}</span>
                        </li>
                        @endforeach
                    </ul>
                </details>
                @endif

                @if($own && ! $comment->trashed())
                <div class="mt-0.5 flex gap-3 text-xs">
                    <button wire:click="startEdit({{ $comment->id }})" class="text-gray-400 hover:text-fw-navy">Bearbeiten</button>
                    <button wire:click="delete({{ $comment->id }})" wire:confirm="Nachricht wirklich löschen?" class="text-gray-400 hover:text-fw-red">Löschen</button>
                </div>
                @endif
                @endif
            </div>
        </div>
        @empty
        <p class="text-xs text-gray-400">Noch keine Nachrichten.</p>
        @endforelse
    </div>

    @if($canWrite)
    <form wire:submit="send" class="mt-3 flex items-end gap-2">
        <textarea wire:model="body" rows="1" placeholder="Nachricht schreiben …"
                  class="flex-1 rounded-xl border-gray-300 text-sm resize-none focus:border-fw-navy focus:ring-fw-navy"></textarea>
        <button type="submit"
                class="shrink-0 p-2.5 rounded-xl bg-fw-red text-white hover:bg-fw-red-dark"
                title="Senden">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
            </svg>
        </button>
    </form>
    @error('body') <p class="mt-1 text-xs text-fw-red">{{ $message }}</p> @enderror
    @endif
</div>
