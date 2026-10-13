{{-- Dienstbeteiligung per year: x of y Dienste, percent with a bar --}}
@foreach($attendance as $y => $a)
    <div class="flex items-center gap-3 text-sm">
        <span class="w-12 shrink-0 font-medium text-gray-700">{{ $y }}</span>
        @if($a['total'] === 0)
            <span class="text-gray-500">keine Dienste mit erfasster Anwesenheit</span>
        @else
            <span class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100"><span class="block h-full rounded-full {{ $a['percent'] >= 60 ? 'bg-green-600' : ($a['percent'] >= 30 ? 'bg-amber-500' : 'bg-red-600') }}" style="width: {{ $a['percent'] }}%"></span></span>
            <span class="w-40 shrink-0 text-right text-gray-700">{{ $a['attended'] }} von {{ $a['total'] }} · <strong>{{ $a['percent'] }} %</strong></span>
        @endif
    </div>
@endforeach
