{{-- Start page: favourites and recently used files of the Kamerad (ACL checked in the provider) --}}
<div class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Dateien</p>
    @if($favorites->isNotEmpty())
        <p class="mt-1 text-xs text-gray-500">Favoriten</p>
        @foreach($favorites as $entry)
            <a href="{{ route('dateien.index', ['node' => $entry->id]) }}" class="block truncate text-sm font-semibold text-gray-900 hover:text-fw-navy">{{ $entry->kind === 'folder' ? '📁' : '📄' }} {{ $entry->name }}</a>
        @endforeach
    @endif
    @if($recent->isNotEmpty())
        <p class="mt-2 text-xs text-gray-500">Zuletzt verwendet</p>
        @foreach($recent as $entry)
            <a href="{{ route('dateien.index', ['node' => $entry->id]) }}" class="block truncate text-sm text-gray-900 hover:text-fw-navy">📄 {{ $entry->name }}</a>
        @endforeach
    @endif
</div>
