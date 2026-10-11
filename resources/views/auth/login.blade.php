<x-layouts.app title="Anmelden">
    @inject('org', \App\Services\OrganizationSettings::class)
    <div class="min-h-screen flex items-center justify-center bg-fw-grey-bg px-4">
        <div class="w-full max-w-sm">
            {{-- Logo / Header --}}
            <div class="text-center mb-8">
                <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="{{ $org->shortName() }}" class="h-28 w-auto mx-auto mb-4">
                <h1 class="text-2xl font-bold text-fw-grey-dark">{{ $org->shortName() }}</h1>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-fw-grey-light p-6">
                <livewire:auth.login-form />
            </div>
        </div>
    </div>
</x-layouts.app>
