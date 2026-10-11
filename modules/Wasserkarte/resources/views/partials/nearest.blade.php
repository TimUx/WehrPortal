{{-- The nearest sources with distance; usable ones first, broken ones marked --}}
@foreach($nearest as $row)
    @php($s = $row['source'])
    <a href="{{ route('wasserkarte.source', $s) }}" class="flex items-center justify-between gap-3 py-1.5 text-sm" wire:key="n-{{ $s->id }}">
        <span class="flex min-w-0 items-center gap-2">
            <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ \Modules\Wasserkarte\src\Models\Source::COLORS[$s->status] }}"></span>
            <span class="min-w-0"><span class="block truncate {{ $s->status === 'available' ? 'text-gray-900' : 'text-red-700 line-through' }}">{{ $s->label() }}{{ $s->location ? ' · '.$s->location : '' }}</span>
                <span class="block text-xs text-gray-500">{{ $s->status === 'available' ? ($s->performance() ?: 'keine Leistungsdaten') : $s->statusLabel().($s->status_note ? ': '.$s->status_note : '') }}</span></span>
        </span>
        <span class="shrink-0 text-sm font-semibold text-gray-900">{{ $row['meters'] < 1000 ? $row['meters'].' m' : str_replace('.', ',', (string) round($row['meters'] / 1000, 1)).' km' }}</span>
    </a>
@endforeach
