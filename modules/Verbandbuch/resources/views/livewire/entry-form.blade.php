@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
@php($error = 'text-sm text-red-600')
<form wire:submit="save" class="mx-auto max-w-2xl space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Eintrag ins Verbandbuch</h1>
        <p class="text-sm text-gray-500">Jede Verletzung im Dienst gehört ins Verbandbuch – auch die kleine. Treten später Beschwerden auf, belegt der Eintrag den Unfall gegenüber der Unfallkasse. Nach dem Speichern lässt sich der Eintrag nicht mehr ändern, nur noch ergänzen.</p>
    </div>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Was ist passiert?</p>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            @foreach(\Modules\Verbandbuch\src\Models\Entry::KINDS as $value => $label)
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2.5 text-sm font-medium has-[:checked]:border-fw-red has-[:checked]:bg-red-50">
                    <input type="radio" wire:model.live="kind" value="{{ $value }}" class="text-fw-red focus:ring-fw-red"> {{ $label }}
                </label>
            @endforeach
        </div>
        @error('kind')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm text-gray-700">Wann?<input type="datetime-local" wire:model="occurredAt" class="{{ $input }}"></label>
            <label class="block text-sm text-gray-700">Wobei?
                <select wire:model="occasion" class="{{ $input }}">
                    <option value="">– bitte wählen –</option>
                    @foreach(\Modules\Verbandbuch\src\Models\Entry::OCCASIONS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </label>
        </div>
        @error('occurred_at')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @error('occasion')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Wo?<input type="text" wire:model="location" maxlength="150" placeholder="z. B. Gerätehaus Fahrzeughalle, Einsatzstelle Hauptstraße 12" class="{{ $input }}"></label>
        @error('location')<p class="{{ $error }}">{{ $message }}</p>@enderror
    </section>

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <label class="block text-sm font-semibold text-gray-900">{{ $hurt ? 'Wer wurde verletzt?' : 'Wer war beteiligt? (optional)' }}
            <select wire:model.live="injuredUserId" class="{{ $input }} font-normal">
                <option value="">– bitte wählen –</option>
                @foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                <option value="{{ \Modules\Verbandbuch\src\Livewire\EntryForm::OTHER }}">Andere Person (kein Kamerad) …</option>
            </select>
        </label>
        @if($injuredUserId === \Modules\Verbandbuch\src\Livewire\EntryForm::OTHER)
            <label class="block text-sm text-gray-700">Name<input type="text" wire:model.blur="injuredName" maxlength="150" placeholder="Vor- und Nachname, z. B. Gast beim Tag der offenen Tür" class="{{ $input }}"></label>
        @endif
        @error('injured_name')<p class="{{ $error }}">{{ $message }}</p>@enderror

        <label class="block text-sm text-gray-700">Hergang – wie ist es passiert?<textarea wire:model="description" rows="4" maxlength="5000" placeholder="{{ $hurt ? 'z. B. Beim Aufrollen des B-Schlauchs mit der Hand an der Kupplung eingeklemmt.' : 'z. B. Leiter beim Aufstellen weggerutscht, niemand verletzt.' }}" class="{{ $input }}"></textarea></label>
        @error('description')<p class="{{ $error }}">{{ $message }}</p>@enderror
        <label class="block text-sm text-gray-700">Zeugen (optional)<input type="text" wire:model="witnesses" maxlength="2000" placeholder="Namen, z. B. Bernd Blaulicht, Hanna Hydrant" class="{{ $input }}"></label>
    </section>

    @if($hurt)
        <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">Verletzung und Erste Hilfe</p>
            <label class="block text-sm text-gray-700">Art und Umfang der Verletzung<textarea wire:model="injury" rows="2" maxlength="2000" placeholder="z. B. Quetschung linker Zeigefinger, kleine Schnittwunde" class="{{ $input }}"></textarea></label>
            @error('injury')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Erste-Hilfe-Maßnahmen{{ $kind === 'injury' ? '' : ' (falls geleistet)' }}<textarea wire:model="firstAidMeasures" rows="2" maxlength="2000" placeholder="z. B. Wunde gereinigt, Pflaster, mit Kühlpack gekühlt" class="{{ $input }}"></textarea></label>
            @error('first_aid_measures')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="block text-sm text-gray-700">Erste Hilfe durch
                    <select wire:model.live="firstAiderUserId" class="{{ $input }}">
                        <option value="">– bitte wählen –</option>
                        @foreach($members as $member)<option value="{{ $member->id }}">{{ $member->name }}</option>@endforeach
                        <option value="{{ \Modules\Verbandbuch\src\Livewire\EntryForm::OTHER }}">Andere Person …</option>
                    </select>
                </label>
                <label class="block text-sm text-gray-700">Wann? (leer = Zeitpunkt des Ereignisses)<input type="datetime-local" wire:model="firstAidAt" class="{{ $input }}"></label>
            </div>
            @if($firstAiderUserId === \Modules\Verbandbuch\src\Livewire\EntryForm::OTHER)
                <label class="block text-sm text-gray-700">Name des Ersthelfers<input type="text" wire:model="firstAiderName" maxlength="150" placeholder="z. B. Rettungsdienst, Sanitäter vor Ort" class="{{ $input }}"></label>
            @endif
            @error('first_aider_name')<p class="{{ $error }}">{{ $message }}</p>@enderror
            @error('first_aid_at')<p class="{{ $error }}">{{ $message }}</p>@enderror
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model="doctorVisited" class="mt-0.5 rounded text-fw-red focus:ring-fw-red">
                <span>Arztbesuch oder Krankenhaus (z. B. Durchgangsarzt) – dann prüft der Sicherheitsbeauftragte, ob eine Unfallanzeige an die Unfallkasse nötig ist.</span>
            </label>
        </section>
    @endif

    <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
        <p class="text-sm font-semibold text-gray-900">Unterschrift</p>
        <p class="text-sm text-gray-500">Mit deiner Unterschrift bestätigst du die Angaben. Danach ist der Eintrag gesperrt; Ergänzungen gehen nur noch als Nachtrag.</p>
        <div wire:key="pad-recorder">
            @include('partials.signature-pad', ['model' => 'signature', 'label' => 'Ersthelfer / Erfasser: '.auth()->user()->name])
        </div>
        @error('signature')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @if($injuredLabel && $injuredUserId !== (string) auth()->id())
            <div wire:key="pad-injured-{{ $injuredUserId }}">
                @include('partials.signature-pad', ['model' => 'injuredSignature', 'label' => 'Verletzte Person (optional): '.$injuredLabel])
            </div>
            @error('injured_signature')<p class="{{ $error }}">{{ $message }}</p>@enderror
        @endif
        <p class="text-xs text-gray-500">Gesundheitsdaten werden verschlüsselt gespeichert. Den Eintrag sehen nur du, die verletzte Person, der Ersthelfer, Sicherheitsbeauftragte und Wehrführung. Er wird {{ \Modules\Verbandbuch\src\Models\Entry::RETENTION_YEARS }} Jahre nach Ende des Jahres gelöscht (DGUV Vorschrift 1 § 24).</p>
    </section>

    @error('entry')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    <div class="flex items-center gap-3">
        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-fw-red px-5 py-2.5 text-sm font-semibold text-white hover:bg-fw-red-dark disabled:opacity-50">Unterschreiben und speichern</button>
        <a href="{{ route('verbandbuch.index') }}" class="text-sm text-gray-500 underline">Abbrechen</a>
    </div>
</form>
