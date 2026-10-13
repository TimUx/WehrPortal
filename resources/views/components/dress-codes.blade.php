@props(['ids' => [], 'class' => 'text-sm text-gray-600'])
{{-- The Kleidung asked for --}}
@php($names = \App\Models\DressCode::names($ids))
@if($names)
    <p {{ $attributes->merge(['class' => $class]) }}><span class="font-medium">Kleidung:</span> {{ implode(', ', $names) }}</p>
@endif
