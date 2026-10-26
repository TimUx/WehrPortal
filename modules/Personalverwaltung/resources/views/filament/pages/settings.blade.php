{{-- Filament has no Tailwind utilities for module views: Filament components plus a few local classes --}}
<x-filament-panels::page>
    <style>
        .pv-stack { display: flex; flex-direction: column; gap: 1rem; }
        .pv-check { display: flex; align-items: center; gap: .75rem; font-size: .875rem; padding: .2rem 0; }
        .pv-label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .25rem; }
        .pv-legend { font-size: .875rem; font-weight: 600; margin-bottom: .25rem; }
        .pv-hint { font-size: .8rem; color: rgb(107 114 128); }
        .pv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: .75rem; }
        .pv-ok { border-radius: .5rem; background: rgb(240 253 244); color: rgb(22 101 52); padding: .5rem .75rem; font-size: .875rem; }
        .pv-error { border-radius: .5rem; background: rgb(254 242 242); color: rgb(153 27 27); padding: .5rem .75rem; font-size: .875rem; }
        .dark .pv-ok { background: rgb(20 83 45 / .4); color: rgb(187 247 208); }
        .dark .pv-error { background: rgb(127 29 29 / .4); color: rgb(254 202 202); }
    </style>

    <div class="pv-stack">
        <p class="pv-hint">Welche persönlichen Daten die Personalverwaltung führt. Sichtbar sind sie nur für Personalverwalter (im Rahmen ihrer Abteilungen) und für den Kameraden selbst – nicht für andere Kameraden und nicht im Admin-Bereich.</p>
        @if($flash)<p class="pv-ok">{{ $flash }}</p>@endif
        @error('settings')<p class="pv-error">{{ $message }}</p>@enderror

        <x-filament::section heading="Daten und Dienstausweis">
            <form wire:submit="save" class="pv-stack">
                <fieldset>
                    <legend class="pv-legend">Felder</legend>
                    @foreach(\Modules\Personalverwaltung\src\Services\PersonnelService::FIELDS as $key => $label)
                        <label class="pv-check"><x-filament::input.checkbox wire:model="fields.{{ $key }}" /> {{ $label }}</label>
                    @endforeach
                    <p class="pv-hint">Ausgeschaltete Felder verschwinden überall; gespeicherte Angaben bleiben erhalten und sind beim Wiedereinschalten wieder da.</p>
                </fieldset>
                <fieldset>
                    <legend class="pv-legend">Selbstpflege</legend>
                    <label class="pv-check"><x-filament::input.checkbox wire:model="fields.self_service" /> Kameraden pflegen ihre Daten selbst (Personalakte → Persönliche Daten)</label>
                    <p class="pv-hint">Ändert ein Kamerad etwas, bekommen die zuständigen Personalverwalter einen Eintrag in der Glocke und eine E-Mail – mit den geänderten Feldern, ohne die Werte.</p>
                </fieldset>
                <fieldset>
                    <legend class="pv-legend">Digitaler Dienstausweis</legend>
                    <label class="pv-check"><x-filament::input.checkbox wire:model="fields.digital_id" /> Aktive Kameraden zeigen ihren Dienstausweis auf dem Handy (Karten-Symbol oben in der Leiste)</label>
                    <p class="pv-hint">Im Scheckkartenformat wie der amtliche Ausweis – mit Foto, Name, Geburtsdatum und -ort, ausstellender Behörde, Ausweisnummer, Feuerwehr, Gültigkeit, Dienstgrad und Abteilung aus der Akte, aber ohne Landeswappen: Er ist die digitale Ausfertigung, kein amtlicher Ausweis. Ein QR-Code, der nur 10 Minuten gilt, zeigt Prüfern den aktuellen Stand – ein ausgetretener Kamerad oder ein abgelaufener Ausweis fällt so sofort auf.</p>
                    <label><span class="pv-label">Ausstellende Behörde</span>
                        <x-filament::input.wrapper><x-filament::input type="text" wire:model="fields.id_issuer" maxlength="150" placeholder="z. B. Gemeinde Musterstadt" /></x-filament::input.wrapper></label>
                    <label class="pv-check"><x-filament::input.checkbox wire:model="fields.id_birth_date" /> Geburtsdatum auf dem Ausweis (wenn in der Akte eingetragen)</label>
                    <label class="pv-check"><x-filament::input.checkbox wire:model="fields.id_birth_on_check" /> Geburtsdatum auch auf der Prüfseite hinter dem QR-Code</label>
                    <label class="pv-check"><x-filament::input.checkbox wire:model="fields.id_require_number" /> Nur zeigen, wenn eine Ausweisnummer eingetragen ist (der digitale gehört dann immer zu einem ausgegebenen Ausweis)</label>
                </fieldset>
                <div><x-filament::button type="submit">Speichern</x-filament::button></div>
            </form>
        </x-filament::section>

        <x-filament::section heading="Dienstzeit" description="Die anrechenbare Dienstzeit ergibt sich aus der Diensthistorie: Zeiten mit Status „Aktiv“ und Dienstzeiten in anderen Wehren, ohne Unterbrechungen. Sie gilt für die Dienstjahre in der Akte und für die fälligen Ehrungen. Ob und ab wann die Zeit in der Jugendfeuerwehr zählt, regelt das jeweilige Land bzw. die Satzung der Ehrung.">
            <form wire:submit="saveRules" class="pv-stack">
                @error('rules')<p class="pv-error">{{ $message }}</p>@enderror
                <div class="pv-grid">
                    <label><span class="pv-label">Abteilung Jugendfeuerwehr</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model="youthGroupId"><option value="">– keine –</option>@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->display_name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label><span class="pv-label">Zeit in der Jugendfeuerwehr</span>
                        <x-filament::input.wrapper><x-filament::input.select wire:model="youthRule"><option value="full">zählt voll</option><option value="age">zählt ab einem Alter</option><option value="none">zählt nicht</option></x-filament::input.select></x-filament::input.wrapper></label>
                    <label><span class="pv-label">ab Alter (Jahre)</span>
                        <x-filament::input.wrapper><x-filament::input type="number" min="6" max="27" wire:model="youthAge" /></x-filament::input.wrapper></label>
                </div>
                <p class="pv-hint">„Ab einem Alter“ braucht das Geburtsdatum (Feld oben einschalten); ohne zählt die Jugendfeuerwehr-Zeit ganz, die Akte weist darauf hin.</p>
                <div><x-filament::button type="submit">Regeln speichern</x-filament::button></div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
