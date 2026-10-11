<x-filament-widgets::widget>
    <x-filament::section heading="Letztes Feedback" icon="heroicon-o-chat-bubble-left-right">
        <x-slot name="afterHeader"><a href="{{ $url }}" style="font-size:.875rem;text-decoration:underline">Alle anzeigen</a></x-slot>
        @if($threads->isEmpty())
            <p style="font-size:.875rem;color:#6b7280">Noch kein Feedback.</p>
        @else
            <ul style="display:flex;flex-direction:column;gap:.5rem;font-size:.875rem">
                @foreach($threads as $thread)
                    <li style="display:flex;justify-content:space-between;gap:1rem">
                        <a href="{{ \App\Filament\Resources\FeedbackThreadResource::getUrl('view', ['record' => $thread]) }}" style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            @if($thread->unread_for_admin)<span title="Ungelesen" style="display:inline-block;width:.5rem;height:.5rem;border-radius:9999px;background:#dc2626;margin-right:.375rem"></span>@endif
                            <strong>{{ $thread->subject }}</strong> <span style="color:#6b7280">– {{ $thread->user?->name }}{{ $thread->isClosed() ? ' · erledigt' : '' }}</span>
                        </a>
                        <span style="color:#6b7280;white-space:nowrap">{{ ($thread->last_message_at ?? $thread->created_at)->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
