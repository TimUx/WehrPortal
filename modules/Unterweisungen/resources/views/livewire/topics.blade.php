@php($in = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-xl font-bold text-gray-900">Arten</h1>
        <button type="button" wire:click="presets" class="text-sm text-fw-navy underline">Übliche Unterweisungen anlegen</button>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">{{ $editing ? 'Art ändern' : 'Neue Art' }}</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            <label class="block text-sm text-gray-700 sm:col-span-2">Name<input type="text" wire:model="form.name" maxlength="150" class="{{ $in }}"></label>
            <label class="block text-sm text-gray-700">Kürzel<input type="text" wire:model="form.short" maxlength="12" placeholder="z. B. UVV" class="{{ $in }}"></label>
            <label class="block text-sm text-gray-700">Art<select wire:model.live="form.kind" class="{{ $in }}">@foreach(\Modules\Unterweisungen\src\Models\Topic::KINDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label class="block text-sm text-gray-700">Wiederholen alle … Monate<input type="number" min="1" max="120" wire:model="form.interval_months" placeholder="leer = einmalig" class="{{ $in }}"></label>
            @if($form['kind'] === 'einweisung')<label class="block text-sm text-gray-700">auf Fahrzeug (optional)<select wire:model="form.vehicle_id" class="{{ $in }}"><option value="">–</option>@foreach($vehicles as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></label>@endif
            <label class="block text-sm text-gray-700">zählt auch als<select wire:model="form.counts_as" class="{{ $in }}"><option value="">–</option>@foreach(\Modules\Unterweisungen\src\Models\Topic::COUNTS_AS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        </div>
        <fieldset class="text-sm"><legend class="text-gray-700">Verpflichtend für</legend>
            <label class="mr-4 inline-flex items-center gap-1.5"><input type="checkbox" wire:model.live="form.for_all" class="rounded"> alle aktiven Kameraden</label>
            @unless($form['for_all'])
                <span class="mt-1 flex flex-wrap gap-3">@foreach($groups as $id => $n)<label class="inline-flex items-center gap-1.5"><input type="checkbox" value="{{ $id }}" wire:model="form.group_ids" class="rounded"> {{ $n }}</label>@endforeach</span>
                <span class="mt-1 flex flex-wrap gap-3">@foreach($functions as $id => $n)<label class="inline-flex items-center gap-1.5"><input type="checkbox" value="{{ $id }}" wire:model="form.function_ids" class="rounded"> {{ $n }}</label>@endforeach</span>
            @endunless
        </fieldset>
        <textarea wire:model="form.description" rows="2" maxlength="2000" placeholder="Inhalt / Grundlage (optional)" class="{{ $in }}"></textarea>
        <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.is_active" class="rounded"> aktiv</label>
        @foreach(['topic_name', 'topic_kind', 'topic_interval'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
        <div class="flex gap-3"><button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Speichern</button>@if($editing)<button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button>@endif</div>
    </form>
    <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
        @forelse($topics as $t)
            <li class="flex flex-wrap items-center justify-between gap-2 p-3 {{ $t->is_active ? '' : 'opacity-60' }}">
                <span><span class="font-medium text-gray-900">{{ $t->label() }}</span>
                    <span class="block text-xs text-gray-500">{{ \Modules\Unterweisungen\src\Models\Topic::KINDS[$t->kind] }}{{ $t->vehicle ? ' · '.$t->vehicle->name : '' }} · {{ $t->interval_months ? 'alle '.$t->interval_months.' Monate' : 'einmalig' }} · für {{ $t->audienceLabel() }}{{ $t->counts_as ? ' · zählt als '.\Modules\Unterweisungen\src\Models\Topic::COUNTS_AS[$t->counts_as] : '' }} · {{ $t->sessions_count }} durchgeführt</span></span>
                <button type="button" wire:click="edit({{ $t->id }})" class="text-xs text-fw-navy underline">Ändern</button>
            </li>
        @empty
            <li class="p-4 text-gray-500">Noch keine Arten – „Übliche Unterweisungen anlegen“ hilft beim Start.</li>
        @endforelse
    </ul>
</div>
