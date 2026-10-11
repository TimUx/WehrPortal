<a href="{{ route('uebungsdienste.index') }}" class="block bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:border-fw-navy">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nächster Übungsdienst</p>
    <p class="mt-1 font-semibold text-gray-900">{{ $service->dateLabel() }}{{ $service->starts_at ? ' · '.$service->starts_at.' Uhr' : '' }}</p>
    <p class="text-sm text-gray-700">{{ $service->topic ?: 'Thema noch offen' }}</p>
    <p class="mt-1 text-sm text-gray-500">{{ $service->responsible->isNotEmpty() ? $service->responsible->pluck('name')->implode(', ') : 'Noch niemand verantwortlich' }}{{ $service->location ? ' · '.$service->location : '' }}</p>
</a>
