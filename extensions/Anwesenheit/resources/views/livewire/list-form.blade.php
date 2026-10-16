@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" data-offline-draft="anwesenheit-list" class="mx-auto max-w-3xl space-y-4"
    x-data="{
        mins() { const s = Date.parse($wire.form.starts_at), e = Date.parse($wire.form.ends_at); return s && e && e > s ? Math.round((e - s) / 60000) : 0; },
        label() { const m = this.mins(); return m ? (Math.floor(m / 60) ? Math.floor(m / 60) + ' Std. ' : '') + (m % 60 || !Math.floor(m / 60) ? (m % 60) + ' Min.' : '') : '–'; },
        count() { return Object.values($wire.selected || {}).filter(Boolean).length + (parseInt($wire.form.guests) || 0); },
    }">
    <h1 class="text-xl font-bold text-gray-900">{{ $listId ? __('anwesenheit::messages.edit_list') : __('anwesenheit::messages.new_list') }}</h1>
    @if(session('flash'))<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('flash') }}</p>@endif
    @error('form')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @unless($listId)<p class="text-sm text-gray-500">{{ __('anwesenheit::messages.standalone_hint') }}</p>@endunless

    @if($prefillOptions)
        <section class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.prefill') }}
                <span class="mt-1 flex gap-2">
                    <select wire:model="prefill" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm">
                        <option value="">–</option>
                        @foreach($prefillOptions as $option)<option value="{{ $option['key'] }}">{{ $option['label'] }}</option>@endforeach
                    </select>
                    <button type="button" wire:click="applyPrefill" class="rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white">{{ __('anwesenheit::messages.prefill_apply') }}</button>
                </span>
            </label>
        </section>
    @endif

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700 sm:col-span-2">{{ __('anwesenheit::messages.title') }}
                <input type="text" wire:model="form.title" data-offline-draft-field maxlength="150" class="{{ $input }}">
                @error('form.title')<span class="{{ $error }}">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.kind') }}
                <select wire:model="form.kind" data-offline-draft-field class="{{ $input }}">@foreach($kinds as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </label>
            @if(count($locations) > 1)
                <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.location') }}
                    <select wire:model.live="form.ext_location_id" class="{{ $input }}">@foreach($locations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @error('form.ext_location_id')<span class="{{ $error }}">{{ $message }}</span>@enderror
                </label>
            @endif
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.starts_at') }}
                <input type="datetime-local" wire:model="form.starts_at" data-offline-draft-field class="{{ $input }}">
                @error('form.starts_at')<span class="{{ $error }}">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.ends_at') }}
                <input type="datetime-local" wire:model="form.ends_at" data-offline-draft-field class="{{ $input }}">
                @error('form.ends_at')<span class="{{ $error }}">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.leader') }}
                <select wire:model="form.leader_id" data-offline-draft-field class="{{ $input }}">
                    <option value="">{{ __('anwesenheit::messages.leader_choose') }}</option>
                    @foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                </select>
                @error('form.leader_id')<span class="{{ $error }}">{{ $message }}</span>@enderror
            </label>
            <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.guests') }}
                <input type="number" min="0" max="999" wire:model="form.guests" data-offline-draft-field class="{{ $input }}">
            </label>
        </div>
        <p class="text-sm text-gray-700">{{ __('anwesenheit::messages.duration') }}: <strong x-text="label()"></strong> · {{ __('anwesenheit::messages.count') }}: <strong x-text="count()"></strong></p>
    </section>

    <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" x-data="{ q: '' }">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold text-gray-900">{{ __('anwesenheit::messages.participants') }}</h2>
            <input type="search" x-model="q" placeholder="{{ __('anwesenheit::messages.participants_search') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-base sm:text-sm">
        </div>
        @error('selected')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <div class="grid max-h-80 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
            @foreach($members as $member)
                <label class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50" x-show="!q || @js(mb_strtolower($member->name)).includes(q.toLowerCase())" wire:key="m-{{ $member->id }}">
                    <input type="checkbox" wire:model="selected.{{ $member->id }}" data-offline-draft-field class="h-5 w-5 rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $member->name }}
                </label>
            @endforeach
        </div>
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.notes') }}
            <textarea wire:model="form.notes" rows="3" maxlength="2000" class="{{ $input }}"></textarea>
        </label>
        <label class="block text-sm text-gray-700">{{ __('anwesenheit::messages.recipients') }}
            <textarea wire:model="form.recipients" rows="2" class="{{ $input }}"></textarea>
            <span class="text-xs text-gray-500">{{ __('anwesenheit::messages.recipients_hint') }}</span>
            @error('form.recipients')<span class="{{ $error }}">{{ $message }}</span>@enderror
        </label>
        <div class="text-sm text-gray-700">
            <p>{{ __('anwesenheit::messages.files') }} <span class="text-xs text-gray-500">(höchstens {{ $maxFiles }})</span></p>
            @foreach($files as $file)
                <p wire:key="f-{{ $file->id }}">{{ $file->filename }} <button type="button" wire:click="removeFile({{ $file->id }})" class="ml-1 text-red-600 underline">entfernen</button></p>
            @endforeach
            <input type="file" wire:model="uploads" multiple class="mt-1 block w-full text-sm">
            @error('uploads')<span class="{{ $error }}">{{ $message }}</span>@enderror
            @error('uploads.*')<span class="{{ $error }}">{{ $message }}</span>@enderror
        </div>
    </section>

    <div class="flex flex-wrap gap-2">
        <button type="submit" x-on:click="$wire.mode = 'submit'" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark">{{ __('anwesenheit::messages.submit') }}</button>
        <button type="submit" x-on:click="$wire.mode = 'draft'" class="rounded-lg border border-gray-300 px-5 py-2.5 text-sm font-semibold text-gray-700">{{ __('anwesenheit::messages.save_draft') }}</button>
    </div>
</form>
