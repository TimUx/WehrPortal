<div class="mx-auto max-w-2xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">{{ $exposure ? ($exposure->status === 'draft' ? 'Exposition bestätigen' : 'Exposition') : 'Exposition erfassen' }}</h1>
        <p class="text-sm text-gray-500">Wer bei Einsatz oder Übung krebserzeugenden, erbgutverändernden oder fortpflanzungsgefährdenden Gefahrstoffen ausgesetzt war, wird erfasst (GefStoffV § 14). Die Angaben werden verschlüsselt gespeichert, 40 Jahre aufbewahrt und vom Expositionsbeauftragten an die Zentrale Expositionsdatenbank (ZED) der DGUV gemeldet – zu deinem Schutz, falls sich Jahre später Folgen zeigen.</p>
    </div>
    @if($exposure)
        <p class="rounded-lg px-3 py-2 text-sm {{ $exposure->status === 'draft' ? 'bg-amber-50 text-amber-900' : 'bg-gray-50 text-gray-700' }}">
            {{ $exposure->person_name }} · {{ $exposure->statusLabel() }}@if($exposure->reported_on) am {{ $exposure->reported_on->format('d.m.Y') }}{{ $exposure->zed_reference ? ' ('.$exposure->zed_reference.')' : '' }}@endif
            @if($exposure->status === 'draft')<span class="block text-xs">Prüfe und ergänze die Angaben (z. B. Dauer, deine Schutzausrüstung) und bestätige sie. Warst du nicht dabei, verwirf den Vorschlag.</span>@endif
        </p>
    @endif
    @error('exposure')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        @if($members->isNotEmpty())
            <label class="block text-sm text-gray-700">Für<select wire:model="memberId" class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">@foreach($members as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></label>
        @endif
        @include('expositionen::partials.fields')
        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">{{ $exposure?->status === 'draft' && $exposure->user_id === auth()->id() ? 'Bestätigen' : 'Speichern' }}</button>
            @if($exposure?->status === 'draft')<button type="button" wire:click="discard" wire:confirm="Vorschlag verwerfen?" class="text-sm text-red-600 underline">Trifft nicht zu – verwerfen</button>@endif
            <a href="{{ route('expositionen.index') }}" class="py-2 text-sm text-gray-500 underline">Abbrechen</a>
        </div>
    </form>
    @if($exposure && $exposure->events->isNotEmpty())
        <ul class="space-y-0.5 text-xs text-gray-500">
            @foreach($exposure->events as $ev)<li>{{ $ev->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} · {{ \Modules\Expositionen\src\Models\ExposureEvent::ACTIONS[$ev->action] ?? $ev->action }} · {{ $ev->user?->name ?? '–' }}</li>@endforeach
        </ul>
        <p class="text-xs text-gray-500">Aufbewahrt bis {{ $exposure->retain_until->format('d.m.Y') }}.</p>
    @endif
</div>
