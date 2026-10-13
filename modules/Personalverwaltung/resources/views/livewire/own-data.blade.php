@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<form wire:submit="save" class="space-y-4 rounded-2xl border border-gray-200 bg-white p-5">
    <p class="text-sm text-gray-600">Diese Angaben sehen nur du und die Personalverwaltung. Änderst du etwas, wird die Personalverwaltung informiert.</p>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('personal')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @include('personalverwaltung::partials.personal-fields', ['fields' => $fields, 'input' => $input])
    <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Speichern</button>
</form>
