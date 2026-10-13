@php($in = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm')
<div class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-xl font-bold text-gray-900">Unterweisung durchführen</h1>
    <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="block text-sm text-gray-700 sm:col-span-2">Unterweisung / Einweisung<select wire:model.live="form.topic_id" class="{{ $in }}"><option value="">– wählen –</option>@foreach($topics as $t)<option value="{{ $t->id }}">{{ $t->label() }}</option>@endforeach</select></label>
            <label class="block text-sm text-gray-700">am<input type="date" wire:model="form.held_on" class="{{ $in }}"></label>
            <label class="block text-sm text-gray-700 sm:col-span-2">Unterwiesen von<input type="text" wire:model="form.instructor_name" maxlength="150" class="{{ $in }}"></label>
        </div>
        @if($events->isNotEmpty())
            <label class="block text-sm text-gray-700">Teilnehmer aus einem Kalendertermin übernehmen (wer anwesend war)
                <select wire:model.live="eventId" class="{{ $in }}"><option value="">–</option>@foreach($events as $e)<option value="{{ $e->id }}">{{ $e->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') }} · {{ $e->title }}</option>@endforeach</select></label>
        @endif
        <fieldset>
            <legend class="flex flex-wrap items-center gap-3 text-sm text-gray-700">Teilnehmer ({{ count($userIds) }})
                <label class="inline-flex items-center gap-1 text-xs"><input type="checkbox" wire:model.live="onlyRequired" class="rounded"> nur, wer sie braucht</label></legend>
            <div class="mt-1 grid max-h-72 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
                @foreach($users as $u)<label class="flex items-center gap-2 text-sm"><input type="checkbox" value="{{ $u->id }}" wire:model.live="userIds" class="rounded"> {{ $u->name }}</label>@endforeach
            </div>
        </fieldset>
        <textarea wire:model="form.notes" rows="2" maxlength="2000" placeholder="Inhalt, Bemerkungen (optional)" class="{{ $in }}"></textarea>
        <label class="block text-sm text-gray-700">Dokumente (optional, je bis 10 MB)<input type="file" wire:model="uploads" multiple class="mt-1 block text-sm"></label>
        @foreach(['session_topic', 'session_date', 'session_users', 'session_instructor', 'session_files'] as $f)@error($f)<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endforeach
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Speichern und unterschreiben lassen</button>
    </form>
</div>
