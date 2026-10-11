@php($colors = ['submitted' => 'bg-amber-50 text-amber-800', 'approved' => 'bg-blue-50 text-blue-800', 'rejected' => 'bg-gray-100 text-gray-600', 'paid' => 'bg-green-50 text-green-800'])
<span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold {{ $colors[$entry->status] ?? '' }}">{{ $entry->statusLabel() }}</span>
