<div class="max-w-3xl mx-auto">
    <a href="{{ route('maengelmanagement.index') }}" class="text-sm text-gray-500 hover:text-gray-900">← Alle Mängel</a>

    @if(session('success'))
        <p class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <div class="mt-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-start justify-between gap-3">
            <h1 class="text-xl font-bold text-gray-900">{{ $defect->title }}</h1>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->status->badgeClasses() }}">{{ $defect->status->label() }}</span>
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->urgency->badgeClasses() }}">{{ $defect->urgency->label() }}</span>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-gray-500">Betrifft</dt><dd class="text-gray-900">{{ $defect->subject() }}@if($defect->device) <span class="font-mono text-xs text-gray-500">{{ $defect->device->code }}</span>@endif</dd></div>
            <div><dt class="text-gray-500">Gemeldet von</dt><dd class="text-gray-900">{{ $defect->reporter->name }}, {{ $defect->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd></div>
            <div><dt class="text-gray-500">Zuständig</dt><dd class="text-gray-900">{{ $defect->assignee?->name ?? 'Noch niemand' }}</dd></div>
            @if($defect->resolved_at)
                <div><dt class="text-gray-500">Abgeschlossen</dt><dd class="text-gray-900">{{ $defect->resolved_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd></div>
            @endif
        </dl>

        @if(filled($defect->description))
            <p class="mt-4 text-sm text-gray-800 whitespace-pre-line">{{ $defect->description }}</p>
        @endif

        @if($photos->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-3">
                @foreach($photos as $photo)
                    <a href="{{ route('maengelmanagement.photo', $photo->id) }}" target="_blank" rel="noopener">
                        <img src="{{ route('maengelmanagement.photo', $photo->id) }}" alt="Foto zum Mangel" loading="lazy"
                             class="h-32 w-32 rounded-lg object-cover border border-gray-200">
                    </a>
                @endforeach
            </div>
        @endif

        @if(auth()->user()->canManageMaengel())
            <a href="{{ \Modules\Maengelmanagement\src\Filament\Resources\DefectResource::getUrl('edit', ['record' => $defect], panel: 'maengelmanagement') }}"
               class="mt-4 inline-flex items-center rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white hover:opacity-90">
                In der Verwaltung bearbeiten
            </a>
        @endif
    </div>

    <h2 class="mt-6 mb-2 text-sm font-semibold text-gray-700">Verlauf</h2>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @foreach($events as $event)
            <div class="p-4 text-sm" wire:key="e-{{ $event->id }}">
                <p class="text-gray-900"><span class="font-semibold">{{ $event->user?->name ?? 'Unbekannt' }}</span> {{ $event->summary() }}</p>
                @if(filled($event->body))
                    <p class="mt-1 text-gray-700 whitespace-pre-line">{{ $event->body }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-500">{{ $event->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</p>
            </div>
        @endforeach
    </div>

    <form wire:submit="addComment" class="mt-4 space-y-2">
        <label for="mm-comment" class="block text-sm font-medium text-gray-700">Kommentar</label>
        <textarea id="mm-comment" wire:model="comment" rows="3" maxlength="3000"
                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red"></textarea>
        @error('comment')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Kommentieren</button>
    </form>
</div>
