<form wire:submit="save" class="space-y-3">
    <div>
        <label for="profile-email" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">E-Mail</label>
        <input id="profile-email" type="email" wire:model="email" autocomplete="email" maxlength="255"
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
        <p class="mt-1 text-xs text-gray-500">Mit dieser Adresse meldest du dich an; Login-Links gehen dorthin. Eine neue Adresse gilt erst, wenn du sie über einen Link bestätigst, den wir an sie schicken.</p>
        @unless(\App\Support\MemberFields::ADDRESS)
        <p class="mt-1 text-xs text-gray-500">Eine Postadresse brauchen wir nicht mehr: Seit Anfang 2025 läuft unsere Kommunikation vollständig per E-Mail.</p>
        @endunless
        @error('email') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror

        @if($pendingEmail)
        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <p>Wir haben eine Bestätigungsmail an <span class="font-semibold">{{ $pendingEmail }}</span> geschickt. Bis du den Link darin bestätigst, gilt weiter deine bisherige Adresse.</p>
            <p class="mt-1 flex gap-4 text-xs">
                <button type="button" wire:click="resend" class="underline hover:no-underline">Mail erneut senden</button>
                <button type="button" wire:click="cancelEmailChange" class="underline hover:no-underline">Änderung abbrechen</button>
            </p>
        </div>
        @endif
    </div>

    @if(\App\Support\MemberFields::PHONE)
    <div>
        <label for="profile-phone" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">Mobiltelefon</label>
        <input id="profile-phone" type="tel" wire:model="phone" autocomplete="tel" maxlength="30"
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
        <p class="mt-1 text-xs text-gray-500">Optional – erscheint bei Buchungskonflikten für andere Kameraden; später auch für Benachrichtigungen per SMS.</p>
        @error('phone') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
    </div>

    @endif

    @if(\App\Support\MemberFields::BIRTH_DATE)
    <div>
        <label for="profile-birth" class="block text-xs font-medium text-gray-500 uppercase tracking-wide">Geburtsdatum</label>
        <input id="profile-birth" type="text" inputmode="numeric" wire:model="birthDate" autocomplete="bday" placeholder="TT.MM.JJJJ" maxlength="10" x-mask="99.99.9999"
               class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red">
        @error('birthDate') <p class="mt-1 text-sm text-fw-red">{{ $message }}</p> @enderror
    </div>

    <label class="flex items-start gap-2 text-sm text-gray-700">
        <input type="checkbox" wire:model="showBirthday" class="mt-0.5 rounded border-gray-300 text-fw-red focus:ring-fw-red">
        <span>Meinen Geburtstag (nur Tag und Monat) auf der Startseite der Kameraden anzeigen</span>
    </label>
    <p class="text-xs text-gray-500">Das Geburtsdatum ist optional. Das volle Geburtsdatum sehen nur du und die Admins.</p>
    @endif

    <div class="flex items-center gap-3">
        <button type="submit" class="inline-flex items-center rounded-lg bg-fw-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-fw-red-dark">Speichern</button>
        @if($saved)
        <span class="text-xs text-fw-green">Gespeichert</span>
        @endif
    </div>
</form>
