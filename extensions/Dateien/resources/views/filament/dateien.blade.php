<x-filament-panels::page>
    <p><a href="{{ route('dateien.index') }}">Dateiablage öffnen</a></p>
    <section style="display: grid; gap: 1rem;">
        <h2>Dateigruppen</h2>
        <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
            @foreach ($groups as $group)
                <x-filament::button color="gray" wire:click="editGroup({{ $group->id }})">{{ $group->name }}</x-filament::button>
            @endforeach
            <x-filament::button wire:click="newGroup">Neue Dateigruppe</x-filament::button>
        </div>
        <form wire:submit="saveGroup" style="display: grid; gap: .5rem;">
            <label>Name <x-filament::input.wrapper><x-filament::input wire:model="groupName" maxlength="150" required /></x-filament::input.wrapper></label>
            @error('name') <p role="alert">{{ $message }}</p> @enderror
            @if (auth()->user()->is_admin && !$groupId && $locations->isNotEmpty())
                <label>Standort
                    <x-filament::input.wrapper><x-filament::input.select wire:model="groupLocationId">
                        <option value="">Gemeinsam (alle Standorte)</option>
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
            @endif
            <div><x-filament::button type="submit">Dateigruppe speichern</x-filament::button></div>
        </form>
        @if ($selectedGroup)
            <h3>Mitglieder: {{ $selectedGroup->name }}</h3>
            @foreach ($members as $member)
                <div wire:key="member-{{ $member->id }}" style="display: flex; gap: 1rem; align-items: center;">
                    <span>{{ $member->name }}{{ $member->pivot->is_manager ? ' (Gruppenverwalter)' : '' }}</span>
                    <x-filament::button color="danger" wire:click="removeMember({{ $member->id }})" wire:confirm="Mitglied aus dieser Dateigruppe entfernen?">Entfernen</x-filament::button>
                </div>
            @endforeach
            <form wire:submit="saveMember" style="display: grid; gap: .5rem;">
                <label>Mitglied
                    <x-filament::input.wrapper><x-filament::input.select wire:model="memberId" required>
                        <option value="">Bitte wählen</option>
                        @foreach ($users as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                @error('memberId') <p role="alert">{{ $message }}</p> @enderror
                <label><x-filament::input.checkbox wire:model="memberIsManager" /> Als Gruppenverwalter kennzeichnen</label>
                <p>Das Kennzeichen dokumentiert die Zuständigkeit. Die Verwaltung erfordert weiterhin die Modulrolle „gruppenverwalter“ oder globale Adminrechte.</p>
                <div><x-filament::button type="submit">Mitglied hinzufügen / aktualisieren</x-filament::button></div>
            </form>
        @endif
    </section>
    @if (auth()->user()->is_admin)
        <section style="display: grid; gap: 1rem;">
            <h2>Ablage anlegen</h2>
            <form wire:submit="createSpace" style="display: grid; gap: .5rem;">
                <label>Name <x-filament::input.wrapper><x-filament::input wire:model="spaceName" required maxlength="150" /></x-filament::input.wrapper></label>
                @error('spaceName') <p role="alert">{{ $message }}</p> @enderror
                @if ($locations->isNotEmpty())
                    <label>Standort
                        <x-filament::input.wrapper><x-filament::input.select wire:model="spaceLocationId">
                            <option value="">Gemeinsam (alle Standorte)</option>
                            @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                        </x-filament::input.select></x-filament::input.wrapper>
                    </label>
                @endif
                <label>Kontingent (MiB, leer = unbegrenzt) <x-filament::input.wrapper><x-filament::input type="number" wire:model="quotaMb" min="1" /></x-filament::input.wrapper></label>
                @error('quotaMb') <p role="alert">{{ $message }}</p> @enderror
                <div><x-filament::button type="submit">Ablage anlegen</x-filament::button></div>
            </form>
        </section>
        <form wire:submit="saveSettings" style="display: grid; gap: 1rem;">
            <h2>Basiskonfiguration</h2>
            {{ $this->form }}
            <div><x-filament::button type="submit">Einstellungen speichern</x-filament::button></div>
        </form>
        <section style="display: grid; gap: .75rem;">
            <h2>Online-Office</h2>
            <p>Öffnet Dokumente im Browser über ONLYOFFICE Docs oder Collabora Online. Bearbeiten dürfen nur Kameraden mit Bearbeitungsrecht; jede Speicherung wird eine neue Version (Zwischenspeicherungen einer Sitzung werden zusammengefasst). Der Office-Server muss die App unter der Rückruf-Adresse erreichen; die App ruft nur die hier eingetragenen Adressen auf, ohne Weiterleitungen (auch im internen Netz).</p>
            <form wire:submit="saveOffice" style="display: grid; gap: .5rem;">
                <label>Anbieter
                    <x-filament::input.wrapper><x-filament::input.select wire:model="office.provider">
                        <option value="">Aus</option>
                        @foreach ($officeProviders as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </x-filament::input.select></x-filament::input.wrapper>
                </label>
                @error('office.provider') <p role="alert">{{ $message }}</p> @enderror
                <label>Adresse des Office-Servers (für den Browser)
                    <x-filament::input.wrapper><x-filament::input type="url" wire:model="office.server_url" placeholder="https://office.ff-musterstadt.de" /></x-filament::input.wrapper>
                </label>
                @error('office.server_url') <p role="alert">{{ $message }}</p> @enderror
                <label>Interne Adresse des Office-Servers (für die App, optional)
                    <x-filament::input.wrapper><x-filament::input type="url" wire:model="office.internal_url" placeholder="http://onlyoffice" /></x-filament::input.wrapper>
                </label>
                @error('office.internal_url') <p role="alert">{{ $message }}</p> @enderror
                <label>Rückruf-Adresse dieser App (für den Office-Server, leer = APP_URL)
                    <x-filament::input.wrapper><x-filament::input type="url" wire:model="office.callback_url" placeholder="http://app:8080" /></x-filament::input.wrapper>
                </label>
                @error('office.callback_url') <p role="alert">{{ $message }}</p> @enderror
                <label>JWT-Secret (ONLYOFFICE, mindestens 32 Zeichen; leer lassen = unverändert)
                    <x-filament::input.wrapper><x-filament::input type="password" wire:model="officeSecret" autocomplete="new-password" /></x-filament::input.wrapper>
                </label>
                <p>{{ $officeHasSecret ? 'Ein Secret ist gespeichert (verschlüsselt).' : 'Kein Secret gespeichert.' }}</p>
                @error('office.secret') <p role="alert">{{ $message }}</p> @enderror
                <fieldset style="display: flex; flex-wrap: wrap; gap: .75rem;">
                    <legend>Im Office öffnen</legend>
                    @foreach ($officeExtensions as $extension)
                        <label><x-filament::input.checkbox wire:model="office.extensions" value="{{ $extension }}" /> {{ $extension }}</label>
                    @endforeach
                </fieldset>
                <label><x-filament::input.checkbox wire:model="office.editing" /> Bearbeiten im Office erlauben (nur {{ implode(', ', $officeEditable) }})</label>
                <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
                    <x-filament::button type="submit">Online-Office speichern</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="testOffice">Verbindung testen</x-filament::button>
                    <x-filament::button type="button" color="danger" wire:click="revokeOfficeSessions" wire:confirm="Alle offenen Office-Sitzungen sofort beenden?">Alle Office-Sitzungen beenden ({{ $officeSessions }})</x-filament::button>
                </div>
            </form>
        </section>
        <section style="display: grid; gap: .5rem;">
            <h2>Speicherprüfung und -bereinigung</h2>
            <p>Vergleicht Dateien auf dem Datenträger mit der Datenbank (wöchentlich automatisch, nach einer Wiederherstellung von Hand: <code>php artisan dateien:reconcile --verify</code>). Die Prüfung löscht nichts.</p>
            @if ($report)
                <ul>
                    <li>Stand: {{ \Illuminate\Support\Carbon::parse($report['checked_at'])->timezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $report['verified'] ? ' (mit Prüfsummen)' : '' }} · {{ $report['versions'] }} Versionen, {{ $report['files'] }} Dateien</li>
                    <li>Fehlende Dateien: {{ count($report['missing']) }}</li>
                    <li>Größe abweichend: {{ count($report['size_mismatch']) }}@if ($report['verified']) · Prüfsumme abweichend: {{ count($report['checksum_mismatch']) }}@endif</li>
                    <li>Verwaiste Dateien: {{ count($report['orphans']) }} ({{ number_format(collect($report['orphans'])->sum('size') / 1048576, 1, ',', '.') }} MiB) · abgebrochene Uploads: {{ $report['stale_staging'] }}</li>
                </ul>
                @foreach (['missing' => 'Fehlt', 'size_mismatch' => 'Größe abweichend', 'checksum_mismatch' => 'Prüfsumme abweichend'] as $key => $label)
                    @foreach (array_slice($report[$key], 0, 20) as $entry)
                        <p role="alert">{{ $label }}: Version {{ $entry['version'] }} von Eintrag #{{ $entry['node'] }} – aus der Sicherung wiederherstellen.</p>
                    @endforeach
                @endforeach
            @else
                <p>Noch keine Prüfung gelaufen.</p>
            @endif
            <div style="display: flex; flex-wrap: wrap; gap: .5rem;">
                <x-filament::button wire:click="runReconcile">Jetzt prüfen</x-filament::button>
                @if ($report && (count($report['orphans']) > 0 || $report['stale_staging'] > 0))
                    <x-filament::button color="danger" wire:click="deleteOrphans" wire:confirm="Verwaiste Dateien ohne Datenbankeintrag endgültig löschen? Vorher wird erneut geprüft; Dateien mit Version werden nie gelöscht.">Verwaiste Dateien löschen</x-filament::button>
                @endif
            </div>
            <p>Ausstehende physische Löschungen: {{ $pending['count'] }}@if ($pending['oldest']) (älteste seit {{ $pending['oldest']->timezone('Europe/Berlin')->format('d.m.Y H:i') }})@endif. Sie werden alle 15 Minuten mit wachsendem Abstand erneut versucht.</p>
            <div><x-filament::button color="gray" wire:click="retryDeletions">Ausstehende Dateilöschungen jetzt erneut versuchen</x-filament::button></div>
        </section>
    @endif
</x-filament-panels::page>
