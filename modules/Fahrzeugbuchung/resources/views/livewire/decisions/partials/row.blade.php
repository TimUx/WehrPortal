<div class="flex items-center gap-2 flex-wrap">
    <span class="text-xs font-semibold px-2 py-0.5 rounded-full text-white" style="background-color: {{ $decision->booking->vehicle->color }}">
        {{ $decision->booking->vehicle->displayName() }}
    </span>
    <span class="text-sm font-medium text-gray-900">
        {{ $decision->booking->starts_at->setTimezone('Europe/Berlin')->isoFormat('dd., D. MMM YYYY, HH:mm') }} Uhr
    </span>
</div>
<p class="mt-1 text-sm text-gray-600">{{ $decision->booking->user->name }}: {{ $decision->booking->purpose }}</p>
