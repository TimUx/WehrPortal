@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-5xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">{{ __('anwesenheit::messages.module') }}</h1>
        <x-page-action :href="route('anwesenheit.create')">{{ __('anwesenheit::messages.new_list') }}</x-page-action>
    </div>
    @if(session('flash'))<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('flash') }}</p>@endif

    <div class="flex flex-wrap gap-2">
        <select wire:model.live="status" class="{{ $input }}" aria-label="Status">
            <option value="">Alle Stände</option>
            <option value="draft">{{ __('anwesenheit::messages.draft') }}</option>
            <option value="submitted">{{ __('anwesenheit::messages.submitted') }}</option>
        </select>
        <input type="number" wire:model.live.debounce.500ms="year" placeholder="Jahr" min="2000" max="2100" class="{{ $input }} w-28" aria-label="Jahr">
    </div>

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($lists as $list)
            <a href="{{ route('anwesenheit.show', $list) }}" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-gray-50" wire:key="l-{{ $list->id }}">
                <div>
                    <p class="font-semibold text-gray-900">{{ $list->title }} <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-normal {{ $list->isSubmitted() ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">{{ $list->isSubmitted() ? __('anwesenheit::messages.submitted') : __('anwesenheit::messages.draft') }}</span></p>
                    <p class="text-sm text-gray-500">{{ $list->kindLabel() }}@if($list->group) · {{ $list->group->display_name }}@endif · {{ $list->starts_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? 'ohne Zeit' }} · {{ $list->durationLabel() }} · Leitung: {{ $list->leader?->name ?? '–' }}</p>
                </div>
                <p class="text-sm text-gray-700">{{ $list->participant_count }} Teilnehmer</p>
            </a>
        @empty
            <p class="px-4 py-6 text-sm text-gray-500">{{ __('anwesenheit::messages.empty') }}</p>
        @endforelse
    </div>
    {{ $lists->links() }}
</div>
