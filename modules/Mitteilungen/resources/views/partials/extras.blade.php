{{-- Attachments and the Lesebestätigung of a Mitteilung; $light for the orange card on the start page --}}
@php($light = $light ?? false)
@if($announcement->files->isNotEmpty())
    <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-sm">
        @foreach($announcement->files as $f)<a href="{{ route('mitteilungen.file', $f) }}" target="_blank" class="{{ $light ? 'text-white' : 'text-fw-navy' }} underline">📎 {{ $f->filename }} <span class="{{ $light ? 'text-white/80' : 'text-gray-500' }} text-xs">({{ $f->sizeLabel() }})</span></a>@endforeach
    </p>
@endif
@if($announcement->needs_ack)
    @if(in_array($announcement->id, $acknowledged ?? [], true))
        <p class="mt-2 text-xs {{ $light ? 'text-white/90' : 'text-green-700' }}">✓ Gelesen bestätigt</p>
    @else
        <form method="POST" action="{{ route('mitteilungen.acknowledge', $announcement) }}" class="mt-2">
            @csrf
            <button type="submit" class="rounded-lg px-3 py-1.5 text-sm font-semibold {{ $light ? 'bg-white text-fw-red-dark' : 'bg-fw-navy text-white' }}">Gelesen und verstanden</button>
        </form>
    @endif
@endif
