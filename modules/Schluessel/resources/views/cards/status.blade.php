<a href="{{ $manage ? route('schluessel.list') : route('schluessel.index') }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    @foreach($lines as $line)<p class="text-sm font-semibold text-amber-900">{{ $line }}</p>@endforeach
    <p class="text-xs text-amber-800">Schlüsselverwaltung</p>
</a>
