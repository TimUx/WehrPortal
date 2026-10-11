<a href="{{ route('wasserkarte.inspections') }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Wasserkarte</p>
    @if($defective)<p class="mt-1 text-sm font-semibold text-red-700">{{ $defective }} Wasserquelle{{ $defective === 1 ? '' : 'n' }} defekt</p>@endif
    @if($overdue)<p class="mt-1 text-sm text-amber-800">{{ $overdue }} Prüfung{{ $overdue === 1 ? '' : 'en' }} überfällig</p>@endif
</a>
