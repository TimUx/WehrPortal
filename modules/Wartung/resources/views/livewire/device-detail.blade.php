@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="mx-auto max-w-3xl space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500">{{ $equipment->code }} · {{ $equipment->category?->name ?? 'ohne Kategorie' }}</p>
            <h1 class="text-xl font-bold text-gray-900">{{ $equipment->name }}</h1>
        </div>
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $equipment->status->badgeClasses() }}">{{ $equipment->status->label() }}</span>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <dl class="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-[12rem_1fr]">
            @if($equipment->manufacturer || $equipment->model)<dt class="text-gray-500">Hersteller / Modell</dt><dd>{{ trim($equipment->manufacturer.' '.$equipment->model) }}</dd>@endif
            @if($equipment->serial_number)<dt class="text-gray-500">Seriennummer</dt><dd>{{ $equipment->serial_number }}</dd>@endif
            <dt class="text-gray-500">Nächste Prüfung</dt><dd class="{{ $equipment->inspectionOverdue() ? 'font-semibold text-red-700' : '' }}">{{ $equipment->next_inspection_at?->format('d.m.Y') ?? '–' }}</dd>
            <dt class="text-gray-500">DGUV-Lebensdauer</dt>
            <dd class="{{ $equipment->lifetimeExpired() ? 'font-semibold text-red-700' : '' }}">
                @if($equipment->lifetimeEndsAt())
                    {{ $equipment->lifetimeYears() }} Jahre – {{ $equipment->lifetimeExpired() ? 'abgelaufen am' : 'bis' }} {{ $equipment->lifetimeEndsAt()->format('d.m.Y') }}
                @elseif($equipment->lifetimeYears())
                    {{ $equipment->lifetimeYears() }} Jahre (Herstellungs- oder Inbetriebnahmedatum fehlt)
                @else
                    keine Höchstnutzungsdauer hinterlegt
                @endif
                @if($equipment->category?->lifetime_basis)<span class="block text-xs text-gray-500">{{ $equipment->category->lifetime_basis }}</span>@endif
            </dd>
        </dl>
        @if(auth()->user()->canManageAusruestung())
            <a href="{{ \Modules\Ausruestung\src\Filament\Resources\EquipmentResource::getUrl('edit', ['record' => $equipment], panel: 'ausruestung') }}" class="mt-2 inline-block text-xs text-fw-navy underline">Gerätedaten, Lebensdauer und Prüfintervall in der Ausrüstungsverwaltung ändern</a>
        @endif
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Wartungen nach Vorlage</p>
        @forelse($templates as $row)
            <div class="flex items-center justify-between gap-3 text-sm">
                <span>{{ $row['template']->name }}<span class="block text-xs text-gray-500">{{ $row['template']->intervalLabel() }}@if($row['next']) · nächste am {{ $row['next']->format('d.m.Y') }}@endif</span></span>
                @if($canPerform)<a href="{{ route('wartung.perform', ['geraet' => $equipment->id, 'vorlage' => $row['template']->id]) }}" class="shrink-0 text-xs text-fw-navy underline">jetzt prüfen</a>@endif
            </div>
        @empty
            <p class="text-sm text-gray-500">Keine Vorlage gilt für dieses Gerät.</p>
        @endforelse
    </section>

    @if($tasks->isNotEmpty())
        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Offene Wartungen</p>
            @foreach($tasks as $task)
                <div class="flex items-center justify-between gap-3 text-sm" wire:key="t-{{ $task->id }}">
                    <span class="{{ $task->isOverdue() ? 'text-red-700' : '' }}">{{ $task->label() }} – {{ $task->isOverdue() ? 'überfällig seit' : 'fällig am' }} {{ $task->due_on->format('d.m.Y') }}{{ $task->provider ? ' · '.$task->provider->name : '' }}</span>
                    @if($canPerform)<a href="{{ route('wartung.perform', ['aufgabe' => $task->id]) }}" class="shrink-0 rounded-lg bg-fw-navy px-3 py-1.5 text-xs font-semibold text-white">Durchführen</a>@endif
                </div>
            @endforeach
        </section>
    @endif

    @if($canManage)
        <details class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Ad-hoc-Wartung planen</summary>
            <div class="mt-2 space-y-2">
                <p class="text-sm text-gray-600">Z. B. nach einem Einsatz, einem Sturz oder wenn eine Fachfirma ohnehin im Haus ist. Zum sofortigen Prüfen „Durchführen“ unten nutzen.</p>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <label class="block text-sm text-gray-700">Vorlage (optional)<select wire:model="templateId" class="{{ $input }}"><option value="">– ohne –</option>@foreach($allTemplates as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></label>
                    <label class="block text-sm text-gray-700">Fällig am<input type="date" wire:model="dueOn" class="{{ $input }}"></label>
                    <label class="block text-sm text-gray-700">Fachfirma (optional)<select wire:model="providerId" class="{{ $input }}"><option value="">– eigene Prüfer –</option>@foreach($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
                    <label class="block text-sm text-gray-700">Was ist zu tun?<input type="text" wire:model="notes" maxlength="500" class="{{ $input }}"></label>
                </div>
                @foreach(['template_id', 'due_on', 'notes', 'wartung'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
                <button type="button" wire:click="plan" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Planen</button>
            </div>
        </details>
    @endif
    @if($canPerform)
        <a href="{{ route('wartung.perform', ['geraet' => $equipment->id]) }}" class="inline-block rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">Durchführen (ad hoc)</a>
    @endif

    <section class="space-y-2">
        <p class="text-sm font-semibold text-gray-900">Prüfprotokolle</p>
        <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
            @forelse($protocols as $p)
                <a href="{{ route('wartung.protocol', $p) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-gray-50" wire:key="p-{{ $p->id }}">
                    <span class="min-w-0"><span class="block {{ $p->isVoided() ? 'text-gray-400 line-through' : 'text-gray-900' }}">{{ $p->title }}</span><span class="block text-xs text-gray-500">{{ $p->code() }} · {{ $p->performed_on->format('d.m.Y') }} · {{ $p->performerLabel() }}</span></span>
                    <span class="text-xs font-semibold {{ $p->passed() ? 'text-green-700' : 'text-red-700' }}">{{ \Modules\Wartung\src\Models\Protocol::RESULTS[$p->result] }}</span>
                </a>
            @empty
                <p class="p-4 text-sm text-gray-500">Noch keine Protokolle.</p>
            @endforelse
        </div>
    </section>
</div>
