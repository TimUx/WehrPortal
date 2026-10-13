{{-- Tragezeit of the year by kind: $wear from ReadinessService::wearTime() --}}
<p class="text-sm text-gray-900">{{ $wear['minutes'] }} min Tragezeit {{ $wear['year'] }}</p>
@if($wear['minutes'] > 0)
    <p class="text-xs text-gray-500">{{ collect($wear['by'])->filter()->map(fn ($m, $k) => \Modules\Atemschutz\src\Services\ReadinessService::WEAR_KINDS[$k].' '.$m.' min')->implode(' · ') }}</p>
@endif
