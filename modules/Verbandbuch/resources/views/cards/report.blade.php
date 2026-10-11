<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Verbandbuch</p>
    <p class="mt-1 text-sm font-semibold text-amber-800">{{ $count }} Unfall{{ $count === 1 ? '' : 'e' }} mit Arztbesuch ohne vermerkte Unfallanzeige</p>
    <a href="{{ route('verbandbuch.index', ['status' => 'report']) }}" class="text-sm text-fw-navy underline">Verbandbuch</a>
</div>
