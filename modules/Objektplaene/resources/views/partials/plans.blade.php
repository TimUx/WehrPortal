{{-- The current plans of an object as buttons --}}
<div class="flex flex-wrap gap-2">
    @forelse($object->documents as $document)
        @if($document->current)
            <a href="{{ $document->current->url() }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg bg-fw-navy font-semibold text-white hover:opacity-90 px-3 py-1.5 text-sm">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M7 3h7l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/></svg>
                {{ $document->title }}
            </a>
        @endif
    @empty
        <span class="text-sm text-gray-500">Noch keine Unterlagen hinterlegt.</span>
    @endforelse
</div>
