<x-layouts.app title="Anmelden">
    @inject('org', \App\Services\OrganizationSettings::class)
    <div class="min-h-screen flex items-center justify-center bg-fw-grey-bg px-4">
        <div class="w-full max-w-sm">
            {{-- Logo / Header --}}
            <div class="text-center mb-8">
                <img src="{{ $org->logoUrl() ?? asset('images/wehrportal-logo.svg') }}" alt="{{ $org->shortName() }}" class="h-28 max-w-full object-contain mx-auto mb-4">
                <h1 class="text-2xl font-bold text-fw-grey-dark">{{ $org->shortName() }}</h1>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-fw-grey-light p-6">
                @inject('authMethods', \App\Services\AuthMethodRegistry::class)
                @if($errors->has('auth'))
                    <p class="mb-4 text-sm text-fw-red">{{ $errors->first('auth') }}</p>
                @endif
                {{-- With an exclusive login method the magic link form is only reachable for local admins on their own page --}}
                @unless($authMethods->magicLinkRestricted() && ! ($localLogin ?? false))
                    <livewire:auth.login-form />
                @endunless
                @foreach($authMethods->enabled() as $method)
                    <a href="{{ $method->loginUrl() }}" class="@if(! $authMethods->magicLinkRestricted() || ($localLogin ?? false)) mt-4 @endif block w-full text-center bg-fw-navy text-white font-semibold py-3 px-4 rounded-xl hover:opacity-90 transition-colors">
                        {{ $method->label() }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>
</x-layouts.app>
