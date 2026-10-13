{{-- Digitaler Dienstausweis on the phone; $id from PersonnelService::digitalIdFor() --}}
@php($org = app(\App\Services\OrganizationSettings::class))
@php($u = $id['user'])
<div class="overflow-hidden rounded-2xl shadow-sm">
    <div class="flex items-center justify-between bg-fw-navy px-4 py-3 text-white">
        <span class="text-sm font-semibold">{{ $org->name() }}</span>
        <img src="{{ $org->logoUrl() ?? asset('images/openwehr-logo.svg') }}" alt="" class="h-7 w-auto brightness-0 invert">
    </div>
    <div class="flex gap-4 bg-white p-4">
        @if($u->avatar)
            <img src="data:{{ $u->avatar->mime }};base64,{{ $u->avatar->data }}" alt="" class="h-24 w-20 shrink-0 rounded-lg object-cover">
        @else
            <div class="flex h-24 w-20 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-400">kein Foto</div>
        @endif
        <div class="min-w-0 text-sm">
            <p class="text-xs uppercase tracking-wide text-gray-500">Dienstausweis</p>
            <p class="text-lg font-bold text-gray-900">{{ $u->name }}</p>
            @if($u->rank)<p class="text-gray-700">{{ $u->rank }}</p>@endif
            @if($u->groups->isNotEmpty())<p class="text-xs text-gray-500">{{ $u->groups->pluck('name')->implode(', ') }}</p>@endif
            <p class="mt-1 text-xs text-gray-600">@if($id['number'])Nr. {{ $id['number'] }} · @endif
                @if($id['valid_until'])<span class="{{ $id['expired'] ? 'font-semibold text-red-700' : '' }}">{{ $id['expired'] ? 'abgelaufen am' : 'gültig bis' }} {{ $id['valid_until']->format('d.m.Y') }}</span>@else ohne Ablaufdatum @endif</p>
        </div>
    </div>
</div>
@unless($u->avatar)<p class="mt-1 text-xs text-gray-500">Tipp: Lade unter Profil ein Foto hoch, dann steht es auf dem Ausweis.</p>@endunless
