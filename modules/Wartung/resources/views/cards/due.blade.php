<div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Wartung</p>
    <ul class="mt-1 space-y-0.5 text-sm text-gray-700">
        @if($overdue > 0)<li class="font-semibold text-red-700">{{ $overdue }} Wartung{{ $overdue === 1 ? '' : 'en' }} überfällig</li>@endif
        <li>{{ $open }} Wartung{{ $open === 1 ? '' : 'en' }} offen</li>
    </ul>
    <a href="{{ route('wartung.index') }}" class="text-sm text-fw-navy underline">Wartungsmanagement</a>
</div>
