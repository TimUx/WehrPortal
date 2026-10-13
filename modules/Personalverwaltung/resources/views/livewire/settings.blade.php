<div class="mx-auto max-w-2xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Einstellungen</h1>
        <p class="text-sm text-gray-500">Welche persönlichen Daten die Personalverwaltung führt. Sichtbar sind sie nur für Personalverwalter (im Rahmen ihrer Abteilungen) und für den Kameraden selbst – nicht für andere Kameraden und nicht im Admin-Bereich.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('settings')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <form wire:submit="save" class="space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <fieldset class="space-y-2">
            <legend class="text-sm font-semibold text-gray-900">Felder</legend>
            @foreach(\Modules\Personalverwaltung\src\Services\PersonnelService::FIELDS as $key => $label)
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="fields.{{ $key }}" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $label }}</label>
            @endforeach
            <p class="text-xs text-gray-500">Ausgeschaltete Felder verschwinden überall; gespeicherte Angaben bleiben erhalten und sind beim Wiedereinschalten wieder da.</p>
        </fieldset>
        <fieldset class="space-y-2 border-t border-gray-100 pt-3">
            <legend class="text-sm font-semibold text-gray-900">Selbstpflege</legend>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="fields.self_service" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Kameraden pflegen ihre Daten selbst (Personalakte → Persönliche Daten)</label>
            <p class="text-xs text-gray-500">Ändert ein Kamerad etwas, bekommen die zuständigen Personalverwalter einen Eintrag in der Glocke und eine E-Mail – mit den geänderten Feldern, ohne die Werte.</p>
        </fieldset>
        <fieldset class="space-y-2 border-t border-gray-100 pt-3">
            <legend class="text-sm font-semibold text-gray-900">Digitaler Dienstausweis</legend>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="fields.digital_id" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> Aktive Kameraden zeigen ihren Dienstausweis auf dem Handy (Personalakte → Nachweise)</label>
            <p class="text-xs text-gray-500">Mit Foto, Name, Dienstgrad, Abteilung, Ausweisnummer und Gültigkeit aus der Akte. Ein QR-Code, der nur 10 Minuten gilt, zeigt Prüfern den aktuellen Stand – ein ausgetretener Kamerad oder ein abgelaufener Ausweis fällt so sofort auf.</p>
        </fieldset>
        <button type="submit" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Speichern</button>
    </form>
</div>
