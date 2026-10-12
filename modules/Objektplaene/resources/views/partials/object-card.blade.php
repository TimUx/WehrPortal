{{-- An object at an Einsatzstelle: name, distance, Gefährdung, features, plans --}}
@php($big = $big ?? false)
<div class="space-y-2 rounded-xl border border-gray-200 p-3">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <a href="{{ route('objektplaene.object', ['object' => $object, 'kiosk' => $big ? 1 : null]) }}" class="font-semibold text-gray-900 underline-offset-2 hover:underline {{ $big ? 'text-xl' : '' }}">{{ $object->name }}</a>
            <p class="text-xs text-gray-500">{{ $object->typeLabel() }}@if(isset($meters)) · {{ $meters }} m @endif @if($object->address) · {{ $object->address }}@endif</p>
        </div>
        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $object->hazardBadge() }}">{{ $object->hazardLabel() }}</span>
    </div>
    @if($object->features())<p class="text-xs font-medium text-gray-700">{{ implode(' · ', $object->features()) }}</p>@endif
    @if($object->hazards)<p class="text-sm text-red-800">{{ $object->hazards }}</p>@endif
    @include('objektplaene::partials.plans', ['object' => $object, 'big' => $big])
</div>
