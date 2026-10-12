<a href="{{ route('objektplaene.index', ['pruefung' => 1]) }}" class="block rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm hover:border-amber-300">
    <p class="text-sm font-semibold text-amber-900">{{ $due }} Objekt{{ $due === 1 ? '' : 'e' }}: Überprüfung der Pläne fällig</p>
    <p class="text-xs text-amber-800">Objektpläne & Brandschutzunterlagen</p>
</a>
