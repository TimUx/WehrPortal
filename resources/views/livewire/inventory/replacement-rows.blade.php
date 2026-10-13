<div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
    @foreach($rows as $row)
        <a href="{{ $row['url'] ?? '#' }}" class="grid grid-cols-1 gap-1 p-3 text-sm hover:bg-gray-50 sm:grid-cols-[1fr_9rem_7rem] sm:items-center">
            <span><span class="font-medium text-gray-900">{{ $row['title'] }}</span><span class="block text-xs text-gray-500">{{ collect([$row['source'], $row['detail'] ?? null])->filter()->implode(' · ') }}</span></span>
            <span class="text-xs text-gray-600">{{ $row['year'] }}</span>
            <span class="text-right text-sm">{{ ($row['cost_cents'] ?? null) !== null ? \App\Support\Money::format($row['cost_cents']) : '–' }}</span>
        </a>
    @endforeach
</div>
