{{-- The main action of a page, next to its title: red, with a "+" (e.g. "Neuer Plan") --}}
@props(['href'])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark']) }}>
    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14"/></svg>
    {{ $slot }}
</a>
