<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <a href="{{ route('anwesenheit.index') }}" class="inline-flex text-sm text-fw-navy underline">← {{ __('anwesenheit::messages.module') }}</a>
            <h1 class="text-xl font-bold text-gray-900">{{ $list->title }}</h1>
            <p class="text-sm text-gray-500">{{ $list->kindLabel() }}@if($list->group) · {{ $list->group->display_name }}@endif · {{ $list->isSubmitted() ? __('anwesenheit::messages.submitted') : __('anwesenheit::messages.draft') }}</p>
        </div>
        <div class="flex flex-wrap gap-2 text-sm">
            <a href="{{ route('anwesenheit.pdf', $list) }}" target="_blank" class="rounded-lg bg-fw-navy px-3 py-2 font-semibold text-white">{{ __('anwesenheit::messages.pdf') }}</a>
            @if($canEdit)<a href="{{ route('anwesenheit.edit', $list) }}" class="rounded-lg border border-gray-300 px-3 py-2">Bearbeiten</a>@endif
            @if($canResend)<button type="button" wire:click="resend" class="rounded-lg border border-gray-300 px-3 py-2">{{ __('anwesenheit::messages.resend') }}</button>@endif
            @if($canDelete)<button type="button" wire:click="delete" wire:confirm="{{ __('anwesenheit::messages.delete_confirm') }}" class="rounded-lg px-3 py-2 text-red-600 underline">{{ __('anwesenheit::messages.delete') }}</button>@endif
        </div>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('anwesenheit::messages.starts_at') }}</dt><dd>{{ $list->starts_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('anwesenheit::messages.ends_at') }}</dt><dd>{{ $list->ends_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('anwesenheit::messages.duration') }}</dt><dd>{{ $list->durationLabel() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('anwesenheit::messages.count') }}</dt><dd>{{ $list->participant_count }}@if($list->guests) <span class="text-gray-500">(davon {{ $list->guests }} Gäste)</span>@endif</dd></div>
            <div><dt class="text-gray-500">{{ __('anwesenheit::messages.leader') }}</dt><dd>{{ $list->leader?->name ?? '–' }}</dd></div>
            <div><dt class="text-gray-500">Standort</dt><dd>{{ $list->group?->display_name ?? '–' }}</dd></div>
            <div class="col-span-2"><dt class="text-gray-500">E-Mail</dt><dd>{{ $list->mailed_at ? __('anwesenheit::messages.mailed').' ('.$list->mailed_at->timezone('Europe/Berlin')->format('d.m.Y H:i').')' : __('anwesenheit::messages.not_mailed') }}@if($recipients) <span class="text-gray-500">→ {{ implode(', ', $recipients) }}</span>@endif</dd></div>
        </dl>
    </section>

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <h2 class="mb-2 font-semibold text-gray-900">{{ __('anwesenheit::messages.participants') }}</h2>
        <ul class="columns-1 text-sm sm:columns-2">
            @foreach($list->participants as $person)<li>{{ $person->name }}@if($person->id === $list->leader_id) <span class="text-gray-500">(Leitung)</span>@endif</li>@endforeach
        </ul>
    </section>

    @if($list->notes)<section class="rounded-2xl border border-gray-100 bg-white p-4 text-sm shadow-sm"><h2 class="mb-1 font-semibold text-gray-900">{{ __('anwesenheit::messages.notes') }}</h2><p class="whitespace-pre-line">{{ $list->notes }}</p></section>@endif

    @if($list->files->isNotEmpty())
        <section class="rounded-2xl border border-gray-100 bg-white p-4 text-sm shadow-sm">
            <h2 class="mb-1 font-semibold text-gray-900">{{ __('anwesenheit::messages.files') }}</h2>
            <ul>@foreach($list->files as $file)<li><a class="text-fw-navy underline" href="{{ route('anwesenheit.file', [$list, $file]) }}">{{ $file->filename }}</a> <span class="text-gray-500">({{ number_format($file->size / 1024, 0, ',', '.') }} KB)</span></li>@endforeach</ul>
        </section>
    @endif
</div>
