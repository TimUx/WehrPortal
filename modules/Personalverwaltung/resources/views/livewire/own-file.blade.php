@php($card = 'space-y-3 rounded-2xl border border-gray-200 bg-white p-5')
<div class="space-y-4">
    @if(\Modules\Personalverwaltung\src\Services\PersonnelService::canManage(auth()->user()))
        <a href="{{ route('personalverwaltung.index') }}" class="block rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-fw-navy hover:border-fw-navy">Zur Personalverwaltung (alle Kameraden, Ehrungen, Dienstbeteiligung) →</a>
    @endif
    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Laufbahn</h2>
        @if($years !== null)<p class="text-sm text-gray-600">{{ $years }} Dienstjahre seit dem Eintritt am {{ auth()->user()->joined_at->format('d.m.Y') }}.</p>@endif
        @include('personalverwaltung::partials.history', ['history' => $history])
    </section>
    <section class="{{ $card }}">
        <h2 class="text-sm font-semibold text-gray-900">Ehrungen</h2>
        <ul class="divide-y divide-gray-100 text-sm">
            @forelse($honors as $h)
                <li class="flex justify-between gap-2 py-1.5"><span class="font-medium text-gray-900">{{ $h->title }}@if($h->awarded_by)<span class="font-normal text-gray-500"> · {{ $h->awarded_by }}</span>@endif</span><span class="text-xs text-gray-500">{{ $h->awarded_on->format('d.m.Y') }}</span></li>
            @empty
                <li class="py-1.5 text-gray-500">Noch keine Ehrungen eingetragen.</li>
            @endforelse
        </ul>
    </section>
    @if($profile?->id_card_number || $profile?->id_card_valid_until)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Dienstausweis</h2>
            <p class="text-sm text-gray-700">{{ $profile->id_card_number ? 'Nr. '.$profile->id_card_number : '' }}@if($profile->id_card_valid_until) · gültig bis {{ $profile->id_card_valid_until->format('d.m.Y') }}@endif</p>
        </section>
    @endif
    @if($attendance->isNotEmpty())
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold text-gray-900">Meine Dienstbeteiligung</h2>
            @include('personalverwaltung::partials.attendance', ['attendance' => $attendance])
        </section>
    @endif
</div>
