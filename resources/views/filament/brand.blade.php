{{-- Title of the admin area: "<Wehr> Admin", the app version smaller behind it --}}
<span style="display: inline-flex; align-items: center; gap: .375rem; min-width: 0; max-width: 100%;">
    <img src="{{ app(\App\Services\OrganizationSettings::class)->logoUrl() ?? asset('images/wehrportal-logo.svg') }}" alt="" style="width: 2rem; height: 2rem; flex-shrink: 0; object-fit: contain;">
    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $name }}">{{ $name }}</span>
    <span class="fi-app-version" style="font-size: .7rem; font-weight: 400; line-height: 1rem; color: rgb(156 163 175);">{{ config('app.version') }}</span>
</span>
