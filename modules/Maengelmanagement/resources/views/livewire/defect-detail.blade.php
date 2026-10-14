@php($input = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
<div class="max-w-3xl mx-auto">
    <a href="{{ route('maengelmanagement.index') }}" class="inline-flex items-center gap-1 rounded-lg py-1.5 pr-2 text-sm font-medium text-fw-navy hover:text-fw-red">← Alle Mängel</a>

    @if(session('success'))
        <p class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('success') }}</p>
    @endif
    @if($flash)
        <p class="mt-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>
    @endif
    @if($defect->question_open_at)
        <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">
            @if($isReporter)<strong>Rückfrage an dich</strong> – bitte unten antworten (ein Foto hilft oft).@else<strong>Rückfrage offen</strong> seit {{ $defect->question_open_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }} – wartet auf {{ $defect->reporter->name }}.@endif
        </p>
    @endif

    <div class="mt-2 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-start justify-between gap-3">
            <h1 class="text-xl font-bold text-gray-900">{{ $defect->title }}</h1>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->status->badgeClasses() }}">{{ $defect->status->label() }}</span>
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold {{ $defect->urgency->badgeClasses() }}">{{ $defect->urgency->label() }}</span>
            </div>
        </div>

        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-gray-500">Betrifft</dt><dd class="text-gray-900">
                @if($defect->vehicle_id && app(\App\Services\ModuleRegistry::class)->isActive('fahrzeuge') && auth()->user()->canUseModule('fahrzeuge'))<a href="{{ route('fahrzeuge.show', $defect->vehicle_id) }}" class="underline">{{ $defect->subject() }}</a>
                @elseif($defect->equipment_id && app(\App\Services\ModuleRegistry::class)->isActive('ausruestung') && auth()->user()->canUseModule('ausruestung'))<a href="{{ route('ausruestung.show', $defect->equipment_id) }}" class="underline">{{ $defect->subject() }}</a>
                @else{{ $defect->subject() }}@endif
                @if($defect->device) <span class="font-mono text-xs text-gray-500">{{ $defect->device->code }}</span>@endif</dd></div>
            <div><dt class="text-gray-500">Gemeldet von</dt><dd class="text-gray-900">{{ $defect->reporter->name }}, {{ $defect->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd></div>
            <div><dt class="text-gray-500">Zuständig</dt><dd class="text-gray-900">{{ $defect->assignee?->name ?? 'Noch niemand' }}
                @if($canManage && $defect->assigned_to !== auth()->id() && $defect->status->isActive())<button type="button" wire:click="takeOver" class="ml-2 text-xs text-fw-navy underline">übernehmen</button>@endif</dd></div>
            @if($defect->resolved_at)
                <div><dt class="text-gray-500">Abgeschlossen</dt><dd class="text-gray-900">{{ $defect->resolved_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</dd></div>
            @endif
        </dl>

        @if(filled($defect->description))
            <p class="mt-4 text-sm text-gray-800 whitespace-pre-line">{{ $defect->description }}</p>
        @endif

        @if($reportPhotos->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-3">
                @foreach($reportPhotos as $photo)
                    <a href="{{ route('maengelmanagement.photo', $photo->id) }}" target="_blank" rel="noopener">
                        <img src="{{ route('maengelmanagement.photo', $photo->id) }}" alt="Foto zum Mangel" loading="lazy" class="h-32 w-32 rounded-lg object-cover border border-gray-200">
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if($canEdit)
        <form wire:submit="saveWork" class="mt-4 space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900">Bearbeiten</h2>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <label class="block text-xs text-gray-600">Status<select wire:model="status" class="{{ $input }} mt-1">@foreach(\Modules\Maengelmanagement\src\Enums\DefectStatus::cases() as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-600">Dringlichkeit<select wire:model="urgency" class="{{ $input }} mt-1">@foreach(\Modules\Maengelmanagement\src\Enums\Urgency::cases() as $u)<option value="{{ $u->value }}">{{ $u->label() }}</option>@endforeach</select></label>
                <label class="block text-xs text-gray-600">Zuständig<select wire:model="assignee" class="{{ $input }} mt-1"><option value="">– niemand –</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></label>
            </div>
            <textarea wire:model="note" rows="2" maxlength="3000" placeholder="Notiz zur Änderung (z. B. Ersatzteil bestellt, Werkstatt informiert)" class="{{ $input }}"></textarea>
            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Speichern</button>
                @if($canManage)<a href="{{ \Modules\Maengelmanagement\src\Filament\Resources\DefectResource::getUrl('edit', ['record' => $defect]) }}" class="text-xs text-gray-500 underline">in der Verwaltung</a>@endif
            </div>
        </form>
    @endif

    <h2 class="mt-6 mb-2 text-sm font-semibold text-gray-700">Verlauf</h2>
    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        @foreach($events as $event)
            <div class="p-4 text-sm {{ $event->type === 'question' ? 'bg-amber-50' : '' }}" wire:key="e-{{ $event->id }}">
                <p class="text-gray-900"><span class="font-semibold">{{ $event->user?->name ?? 'Unbekannt' }}</span> {{ $event->summary() }}</p>
                @if(filled($event->body))
                    <p class="mt-1 text-gray-700 whitespace-pre-line">{{ $event->body }}</p>
                @endif
                @if($event->photos->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach($event->photos as $photo)
                            <a href="{{ route('maengelmanagement.photo', $photo->id) }}" target="_blank" rel="noopener"><img src="{{ route('maengelmanagement.photo', $photo->id) }}" alt="Foto" loading="lazy" class="h-20 w-20 rounded-lg object-cover border border-gray-200"></a>
                        @endforeach
                    </div>
                @endif
                <p class="mt-1 text-xs text-gray-500">{{ $event->created_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i') }}</p>
            </div>
        @endforeach
    </div>

    <form wire:submit="send" class="mt-4 space-y-2">
        <div class="flex flex-wrap gap-2 text-sm">
            <button type="button" wire:click="$set('mode', 'comment')" class="rounded-full px-3 py-1 {{ $mode === 'comment' ? 'bg-fw-navy text-white' : 'bg-gray-100 text-gray-700' }}">{{ $isReporter && $defect->question_open_at ? 'Antworten' : 'Kommentar' }}</button>
            @if($canEdit && ! $isReporter && $defect->status->isActive())
                <button type="button" wire:click="$set('mode', 'question')" class="rounded-full px-3 py-1 {{ $mode === 'question' ? 'bg-amber-600 text-white' : 'bg-gray-100 text-gray-700' }}">Rückfrage an {{ $defect->reporter->name }}</button>
            @endif
            @if($canReopen)
                <button type="button" wire:click="$set('mode', 'reopen')" class="rounded-full px-3 py-1 {{ $mode === 'reopen' ? 'bg-fw-red text-white' : 'bg-gray-100 text-gray-700' }}">Doch nicht behoben</button>
            @endif
        </div>
        <textarea wire:model="comment" rows="3" maxlength="3000" aria-label="Text" class="{{ $input }}"
                  placeholder="{{ ['question' => 'Was muss der Melder noch sagen? z. B. Welches Fach genau?', 'reopen' => 'Was ist noch nicht in Ordnung?'][$mode] ?? 'Kommentar' }}"></textarea>
        @error('comment')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        <input type="file" wire:model="photos" multiple accept="image/*" capture="environment" class="text-sm">
        @if($photos)<p class="text-xs text-gray-500">{{ count($photos) }} Foto{{ count($photos) === 1 ? '' : 's' }} angehängt</p>@endif
        @foreach(['photos', 'photos.*'] as $field)@error($field)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
        <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ ['question' => 'Rückfrage stellen', 'reopen' => 'Wieder öffnen'][$mode] ?? ($isReporter && $defect->question_open_at ? 'Antworten' : 'Kommentieren') }}</button>
    </form>
</div>
