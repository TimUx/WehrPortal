{{-- White on the brand red is 4.2:1, just under Filament's 4.5:1 threshold, so Filament
     would pick a lighter shade with dark text. Force the look used in the frontend. --}}
<style>
    /* Filament hides the brand on phones by default. Keep it visible without crowding the controls. */
    .fi-topbar .fi-topbar-start {
        display: flex;
        flex: 1;
        min-width: 0;
        margin-inline-end: .75rem;
    }
    .fi-topbar .fi-topbar-start > a {
        flex: 1;
        min-width: 0;
        overflow: hidden;
    }
    .fi-topbar .fi-logo {
        min-width: 0;
        max-width: 100%;
        margin-inline-start: 0;
    }
    .fi-topbar .fi-topbar-end {
        flex-shrink: 0;
    }
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
