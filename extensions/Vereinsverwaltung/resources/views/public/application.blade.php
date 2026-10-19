<x-layouts.app :title="'Beitritt – '.config('vereinsverwaltung.association_name')">
    <main class="mx-auto max-w-3xl space-y-6 px-4 py-8">
        <header>
            <h1 class="text-2xl font-bold text-fw-navy">Beitritt zu {{ config('vereinsverwaltung.association_name') }}</h1>
            <p class="mt-2 text-sm text-gray-700">
                Der Jahresbeitrag beträgt {{ number_format(config('vereinsverwaltung.annual_fee_cents', 1500) / 100, 2, ',', '.') }} €.
                Er wird üblicherweise im {{ config('vereinsverwaltung.billing_month', 12) }}. Monat für das
                {{ config('vereinsverwaltung.billing_year_offset', 1) === 1 ? 'folgende' : 'laufende' }} Jahr fällig.
            </p>
        </header>

        @if (session('submitted'))
            <div role="status" class="rounded-lg bg-green-50 p-4 text-green-900">
                Vielen Dank. Ihr Beitrittsantrag wurde übermittelt und wird vom Vereinsvorstand geprüft.
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="rounded-lg bg-red-50 p-4 text-red-900">
                Bitte prüfen Sie die markierten Angaben. Aus Sicherheitsgründen werden eingegebene Daten nach einem
                Validierungsfehler nicht erneut in das Formular eingesetzt.
            </div>
        @endif

        <form method="post" action="{{ route('verein.application.store') }}" class="space-y-6">
            @csrf
            <div class="hidden" aria-hidden="true">
                <label for="website">Website</label>
                <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
            </div>

            <fieldset class="space-y-4 rounded-lg border border-gray-300 p-4">
                <legend class="px-2 font-semibold">Persönliche Angaben</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Titel <input name="title" maxlength="30" autocomplete="honorific-prefix" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Vorname * <input name="first_name" required maxlength="100" autocomplete="given-name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Nachname * <input name="last_name" required maxlength="100" autocomplete="family-name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Geburtsdatum * <input name="birth_date" type="date" required max="{{ now()->subDay()->toDateString() }}" autocomplete="bday" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Familienstand <input name="marital_status" maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Telefon <input name="phone" type="tel" maxlength="50" autocomplete="tel" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Mobiltelefon <input name="mobile" type="tel" maxlength="50" autocomplete="mobile tel" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">E-Mail * <input name="email" type="email" required maxlength="254" autocomplete="email" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block sm:col-span-2">Straße und Hausnummer * <input name="street" required maxlength="255" autocomplete="street-address" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Postleitzahl * <input name="postal_code" required maxlength="20" autocomplete="postal-code" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Wohnort * <input name="city" required maxlength="100" autocomplete="address-level2" class="mt-1 block w-full rounded border-gray-300"></label>
                </div>
                @foreach (['title' => 'Titel', 'first_name' => 'Vorname', 'last_name' => 'Nachname', 'birth_date' => 'Geburtsdatum', 'marital_status' => 'Familienstand', 'phone' => 'Telefon', 'mobile' => 'Mobiltelefon', 'email' => 'E-Mail', 'street' => 'Straße und Hausnummer', 'postal_code' => 'Postleitzahl', 'city' => 'Wohnort'] as $field => $label)
                    @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @endforeach
            </fieldset>

            <fieldset class="space-y-4 rounded-lg border border-gray-300 p-4">
                <legend class="px-2 font-semibold">Bankverbindung zur späteren Mandatsdokumentation</legend>
                <p class="text-sm text-gray-700">Diese Angaben werden verschlüsselt gespeichert und sind nur für den Vereinsvorstand sichtbar. Der Online-Antrag allein erteilt noch kein SEPA-Lastschriftmandat; dieses wird separat bestätigt und dokumentiert. Es findet keine Zahlung über die App statt.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Kontoinhaber * <input name="account_holder" required maxlength="200" autocomplete="name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Kreditinstitut * <input name="bank_name" required maxlength="200" autocomplete="organization" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">BIC * <input name="bic" required maxlength="11" autocomplete="off" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">IBAN * <input name="iban" required maxlength="34" autocomplete="off" class="mt-1 block w-full rounded border-gray-300"></label>
                </div>
                @foreach (['account_holder' => 'Kontoinhaber', 'bank_name' => 'Kreditinstitut', 'bic' => 'BIC', 'iban' => 'IBAN'] as $field => $label)
                    @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @endforeach
            </fieldset>

            <div class="space-y-4">
                <label class="flex items-start gap-3">
                    <input name="privacy_consent" type="checkbox" value="1" required class="mt-1 rounded border-gray-300">
                    <span>Ich willige ein, dass meine Angaben zur Bearbeitung des Beitrittsantrags und zur Vereinsverwaltung verarbeitet werden. Zugriff erhalten der Vereinsvorstand und technisch berechtigte Systemadministratoren. *</span>
                </label>
                @error('privacy_consent')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <label class="flex items-start gap-3">
                    <input name="sepa_notice" type="checkbox" value="1" required class="mt-1 rounded border-gray-300">
                    <span>Ich habe zur Kenntnis genommen, dass mein Online-Antrag noch kein SEPA-Lastschriftmandat erteilt und ein Mandat separat bestätigt und dokumentiert werden muss. *</span>
                </label>
                @error('sepa_notice')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="rounded bg-fw-navy px-5 py-3 font-semibold text-white">Beitrittsantrag senden</button>
            <p class="text-xs text-gray-600">* Pflichtangabe. Eine elektronische Unterschrift wird mit diesem Formular nicht erfasst.</p>
        </form>
    </main>
</x-layouts.app>
