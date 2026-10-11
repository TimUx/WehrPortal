<div class="mt-3 border-t border-gray-100 pt-3">
    @if($sent)
        <p class="mb-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">Deine Nachricht ist beim Kleiderwart angekommen.</p>
    @endif

    <form wire:submit="send" class="space-y-2">
        <label class="block text-sm font-medium text-gray-700" for="msg-{{ $issuedItemId }}">Nachricht an den Kleiderwart</label>
        <textarea id="msg-{{ $issuedItemId }}" wire:model="body" rows="3" maxlength="2000"
                  placeholder="z. B. Reißverschluss defekt, Größe passt nicht …"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red"></textarea>
        @error('body')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">
            Senden
        </button>
    </form>

    @if($messages->isNotEmpty())
        <ul class="mt-4 space-y-2">
            @foreach($messages as $message)
                <li class="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                    <p class="text-gray-800 whitespace-pre-line">{{ $message->body }}</p>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ $message->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}
                        · {{ $message->isHandled() ? 'erledigt' : 'offen' }}
                    </p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
