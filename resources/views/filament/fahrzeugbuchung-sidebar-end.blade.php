{{-- Below the Fahrzeugbuchung admin navigation: station monitor (new tab) and app version --}}
<div class="-mx-2">
    <ul class="fi-sidebar-group-items flex flex-col gap-y-1">
        <x-filament-panels::sidebar.item
            :url="route('fahrzeugbuchung.monitor')"
            icon="heroicon-o-tv"
            :should-open-url-in-new-tab="true"
            title="Öffnet in einem neuen Fenster"
        >
            Wachen-Monitor
            <x-filament::icon
                icon="heroicon-m-arrow-top-right-on-square"
                class="inline-block h-4 w-4 text-gray-400 dark:text-gray-500"
                style="margin-inline-start: 0.25rem; vertical-align: -0.15em;"
            />
        </x-filament-panels::sidebar.item>
    </ul>

    <p class="fi-app-version px-2 text-gray-400 dark:text-gray-500" style="margin-top: 0.5rem; font-size: 0.7rem; line-height: 1rem;">
        {{ config('app.version') }}
    </p>
</div>
