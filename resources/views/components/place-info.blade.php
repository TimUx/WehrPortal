@props(['place' => null, 'location' => null, 'class' => 'text-sm text-gray-600'])
{{-- The Ort of a Termin or Dienst: a saved place with address and navigation, or the free text (a known place name gets its address too) --}}
@php($place ??= \App\Models\Place::forLocation($location))
@if($place)
    <p {{ $attributes->merge(['class' => $class]) }}>
        <span class="font-medium">{{ $place->name }}</span>@if($place->address)<span>, {{ $place->address }}</span>@endif
        · <a href="{{ $place->navigationUrl() }}" target="_blank" rel="noopener" class="text-fw-navy underline">Navigation</a>
    </p>
@elseif(filled($location))
    <p {{ $attributes->merge(['class' => $class]) }}>{{ $location }}</p>
@endif
