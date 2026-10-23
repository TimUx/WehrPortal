@if(config('locations.enabled') && auth()->check())
    <section aria-label="Standortkontext" style="padding: 0.75rem 1rem; border-bottom: 1px solid #d1d5db; background: #fff; color: #1B2645;">
        @include('locations::partials.switcher', ['contextId' => 'app-location'])
    </section>
@endif
