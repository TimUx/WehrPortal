{{-- White on the brand red is 4.2:1, just under Filament's 4.5:1 threshold, so Filament
     would pick a lighter shade with dark text. Force the look used in the frontend. --}}
<style>
    .fi-btn.fi-color-primary:not(.fi-outlined) {
        background-color: {{ \App\Support\BrandColor::RED }};
        color: #fff;
    }
    .fi-btn.fi-color-primary:not(.fi-outlined):hover {
        background-color: {{ \App\Support\BrandColor::RED_DARK }};
        color: #fff;
    }
    .fi-btn.fi-color-primary:not(.fi-outlined) > .fi-icon {
        color: #fff;
    }
</style>
