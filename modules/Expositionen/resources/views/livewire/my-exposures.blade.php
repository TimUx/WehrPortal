<div class="space-y-4">
    @if(session('expositionen'))<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('expositionen') }}</p>@endif
    @foreach($prompts as $report)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm" wire:key="p-{{ $report->id }}">
            <span class="text-amber-900">Warst du bei <b>Einsatz {{ $report->number }}</b>{{ $report->keyword ? ' ('.$report->keyword.')' : '' }} am {{ $report->alarmed_at->setTimezone('Europe/Berlin')->format('d.m.Y') }} Brandrauch oder anderen Gefahrstoffen ausgesetzt?</span>
            <span class="flex gap-3"><a href="{{ route('expositionen.create', ['einsatz' => $report->id]) }}" class="rounded-lg bg-fw-navy px-3 py-1.5 font-semibold text-white">Erfassen</a>
                <button type="button" wire:click="dismiss({{ $report->id }})" class="text-amber-900 underline">Nein</button></span>
        </div>
    @endforeach
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold text-gray-900">Meine Expositionen</h1>
        <span class="flex gap-3 text-sm"><x-page-action :href="route('expositionen.create')">Exposition erfassen</x-page-action>
            @if($exposures->isNotEmpty())<a href="{{ route('expositionen.certificate', auth()->user()) }}" target="_blank" class="py-1.5 text-fw-navy underline">Nachweis drucken</a>@endif</span>
    </div>
    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
        @forelse($exposures as $e)
            <a href="{{ route('expositionen.edit', $e) }}" class="block p-3 hover:bg-gray-50">
                <span class="flex flex-wrap justify-between gap-2"><span class="font-medium text-gray-900">{{ $e->occasion }}</span><span class="text-xs {{ $e->status === 'draft' ? 'font-semibold text-amber-700' : 'text-gray-500' }}">{{ $e->statusLabel() }}</span></span>
                <span class="block text-xs text-gray-600">{{ $e->occurred_on->format('d.m.Y') }} · {{ $e->activityLabel() }} · {{ $e->substanceList() }}</span>
            </a>
        @empty
            <p class="p-4 text-gray-500">Noch keine Expositionen erfasst.</p>
        @endforelse
    </div>
</div>
