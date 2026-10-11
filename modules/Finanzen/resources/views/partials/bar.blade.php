{{-- Budget bar: paid dark, approved light, red when over budget --}}
@php($over = $row['budget'] > 0 && $row['approved'] + $row['paid'] > $row['budget'])
@php($paidPct = $row['budget'] > 0 ? min(100, $row['paid'] * 100 / $row['budget']) : 0)
@php($approvedPct = $row['budget'] > 0 ? min(100 - $paidPct, $row['approved'] * 100 / $row['budget']) : 0)
<div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100" role="img" aria-label="{{ $row['percent'] ?? 0 }} % des Budgets verplant">
    <div class="flex h-full">
        <div class="h-full {{ $over ? 'bg-red-600' : 'bg-fw-navy' }}" style="width: {{ $paidPct }}%"></div>
        <div class="h-full {{ $over ? 'bg-red-300' : 'bg-fw-navy/40' }}" style="width: {{ $approvedPct }}%"></div>
    </div>
</div>
