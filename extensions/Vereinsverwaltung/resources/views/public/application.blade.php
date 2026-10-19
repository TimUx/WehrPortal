<x-layouts.app title="Vereinsbeitritt">
    <main x-data="{ youth: false, birthDate: '', isFourteen() { const birth = new Date(this.birthDate + 'T00:00:00'); const today = new Date(); let age = today.getFullYear() - birth.getFullYear(); if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--; return age >= 14; } }" class="mx-auto max-w-3xl space-y-6 px-4 py-8">
        <header>
            <h1 class="text-2xl font-bold text-fw-navy">Vereinsbeitritt</h1>
            <p class="mt-2 text-sm text-gray-700">Wählen Sie den Verein aus, dem Sie beitreten möchten.</p>
            <p x-show="!youth" class="mt-2 text-sm text-gray-700">
                Der Jahresbeitrag beträgt {{ number_format(config('vereinsverwaltung.annual_fee_cents', 1500) / 100, 2, ',', '.') }} €.
                Er wird üblicherweise im {{ config('vereinsverwaltung.billing_month', 12) }}. Monat für das
                {{ config('vereinsverwaltung.billing_year_offset', 1) === 1 ? 'folgende' : 'laufende' }} Jahr fällig.
            </p>
            <p x-cloak x-show="youth" class="mt-2 text-sm font-medium text-gray-700">Mitglieder der Jugend- und Kinderfeuerwehr sind beitragsfrei; Bankdaten und SEPA-Angaben sind nicht erforderlich.</p>
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

            <label class="block">Verein *
                <select name="association_name" required class="mt-1 block w-full rounded border-gray-300">
                    <option value="">Bitte Verein auswählen</option>
                    @foreach ($associationNames as $associationName)
                        <option value="{{ $associationName }}">{{ $associationName }}</option>
                    @endforeach
                </select>
            </label>
            @error('association_name')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

            <fieldset class="space-y-4 rounded-lg border border-gray-300 p-4">
                <legend class="px-2 font-semibold">Persönliche Angaben</legend>
                <label class="flex items-start gap-3 rounded bg-blue-50 p-3">
                    <input name="youth_member" type="checkbox" value="1" x-model="youth" class="mt-1 rounded border-gray-300">
                    <span>Ich beantrage die Mitgliedschaft in der Jugend- oder Kinderfeuerwehr. Diese Mitgliedschaft ist beitragsfrei.</span>
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Titel <input name="title" maxlength="30" autocomplete="honorific-prefix" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Vorname * <input name="first_name" required maxlength="100" autocomplete="given-name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Nachname * <input name="last_name" required maxlength="100" autocomplete="family-name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Geburtsdatum * <input name="birth_date" type="date" x-model="birthDate" required max="{{ now()->subDay()->toDateString() }}" autocomplete="bday" class="mt-1 block w-full rounded border-gray-300"></label>
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

            <fieldset x-cloak x-show="!youth" class="space-y-4 rounded-lg border border-gray-300 p-4">
                <legend class="px-2 font-semibold">Bankverbindung zur späteren Mandatsdokumentation</legend>
                <p class="text-sm text-gray-700">Diese Angaben werden verschlüsselt gespeichert und sind nur für den Vereinsvorstand und technisch berechtigte Systemadministratoren sichtbar. Der Online-Antrag allein erteilt noch kein SEPA-Lastschriftmandat; dieses wird separat bestätigt und dokumentiert. Es findet keine Zahlung über die App statt.</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">Kontoinhaber * <input name="account_holder" x-bind:required="!youth" maxlength="200" autocomplete="name" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">Kreditinstitut * <input name="bank_name" x-bind:required="!youth" maxlength="200" autocomplete="organization" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">BIC * <input name="bic" x-bind:required="!youth" maxlength="11" autocomplete="off" class="mt-1 block w-full rounded border-gray-300"></label>
                    <label class="block">IBAN * <input name="iban" x-bind:required="!youth" maxlength="34" autocomplete="off" class="mt-1 block w-full rounded border-gray-300"></label>
                </div>
                @foreach (['account_holder' => 'Kontoinhaber', 'bank_name' => 'Kreditinstitut', 'bic' => 'BIC', 'iban' => 'IBAN'] as $field => $label)
                    @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                @endforeach
                <label class="flex items-start gap-3">
                    <input name="sepa_notice" type="checkbox" value="1" x-bind:required="!youth" class="mt-1 rounded border-gray-300">
                    <span>Ich habe zur Kenntnis genommen, dass mein Online-Antrag noch kein SEPA-Lastschriftmandat erteilt und ein Mandat separat bestätigt und dokumentiert werden muss. *</span>
                </label>
                @error('sepa_notice')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            </fieldset>

            <div class="space-y-4">
                <details class="rounded-lg border border-gray-200 p-3 text-sm">
                    <summary class="cursor-pointer font-semibold">Was bedeutet die Datenschutzeinwilligung?</summary>
                    <p class="mt-2">Die Angaben werden zur Prüfung des Beitritts und – bei Aufnahme – zur Verwaltung der Vereinsmitgliedschaft verwendet. Zugriff haben der Vereinsvorstand und technisch berechtigte Systemadministratoren. Personen- und Bankdaten werden verschlüsselt gespeichert. Nicht angenommene Anträge werden nach der Entscheidung von ihren persönlichen Angaben bereinigt. Weitere Informationen zu Verantwortlichem, Rechtsgrundlage, Aufbewahrung, Betroffenenrechten und Kontakt erhalten Sie beim Verein.</p>
                </details>
                <label class="flex items-start gap-3">
                    <input name="privacy_consent" type="checkbox" value="1" required class="mt-1 rounded border-gray-300">
                    <span x-show="!youth">Ich willige ein, dass meine Angaben zur Bearbeitung des Beitrittsantrags und zur Vereinsverwaltung verarbeitet werden. *</span>
                    <span x-cloak x-show="youth">Ich willige als Erziehungsberechtigte/r in die Verarbeitung der Angaben des Kindes zur Bearbeitung des Beitrittsantrags und zur Vereinsverwaltung ein. *</span>
                </label>
                @error('privacy_consent')<p class="text-sm text-red-700">{{ $message }}</p>@enderror

                <section class="space-y-3 rounded-lg border border-gray-200 p-4">
                    <div>
                        <h2 class="font-semibold">Foto- und Videoaufnahmen (freiwillig)</h2>
                        <p class="text-sm text-gray-700">Die Einwilligung ist freiwillig und keine Voraussetzung für die Mitgliedschaft.</p>
                    </div>
                    <details class="text-sm">
                        <summary class="cursor-pointer font-semibold">Details zur Fotoeinwilligung</summary>
                        <div class="mt-2 space-y-2">
                            <p>Vereinsbezogene Fotos und Videos können zur Mitgliederwerbung, Öffentlichkeitsarbeit und Dokumentation der Vereinsarbeit auf der Vereinswebsite, in lokalen Print- und Onlinemedien, vereinseigenen Broschüren und Informationsmaterialien sowie offiziellen Social-Media-Kanälen veröffentlicht werden. Eine Nutzung zu anderen Zwecken ist nicht umfasst.</p>
                            <p>Online veröffentlichte Aufnahmen können weltweit abgerufen und von Dritten gespeichert, verändert oder weiterverbreitet werden. Eine vollständige Entfernung bereits verbreiteter Kopien kann nicht garantiert werden. Die Einwilligung kann jederzeit mit Wirkung für die Zukunft beim Verein widerrufen werden.</p>
                            <p>In geschlossenen WhatsApp-Gruppen geteilte Aufnahmen sind nur für private Zwecke bestimmt und dürfen nicht weitergegeben oder öffentlich veröffentlicht werden.</p>
                            <p>Bei Kindern erteilen die Erziehungsberechtigten die Einwilligung. Ab dem 14. Geburtstag ist zusätzlich die eigene Zustimmung des Jugendlichen erforderlich. Dieses Onlineformular erfasst die Zustimmung per Checkbox, aber keine handschriftliche Unterschrift.</p>
                        </div>
                    </details>
                    <label class="flex items-start gap-3">
                        <input name="photo_consent" type="checkbox" value="1" class="mt-1 rounded border-gray-300">
                        <span x-show="!youth">Ich willige freiwillig ein, dass der Verein Fotos und Videos von mir für die oben beschriebenen Zwecke erstellt und veröffentlicht.</span>
                        <span x-cloak x-show="youth">Ich bin erziehungsberechtigt und willige freiwillig ein, dass der Verein Fotos und Videos des Kindes für die oben beschriebenen Zwecke erstellt und veröffentlicht.</span>
                    </label>
                    <label x-cloak x-show="youth && isFourteen()" class="flex items-start gap-3">
                        <input name="youth_photo_consent" type="checkbox" value="1" class="mt-1 rounded border-gray-300">
                        <span>Ich als Jugendlicher ab 14 Jahren stimme der Erstellung und Veröffentlichung meiner Fotos und Videos ebenfalls freiwillig zu. Ohne diese zusätzliche Zustimmung werden für Jugendliche ab 14 Jahren keine Fotos oder Videos aufgrund dieser Erklärung veröffentlicht.</span>
                    </label>
                    @error('photo_consent')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                    @error('youth_photo_consent')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                </section>
            </div>

            <button type="submit" class="rounded bg-fw-navy px-5 py-3 font-semibold text-white">Beitrittsantrag senden</button>
            <p class="text-xs text-gray-600">* Pflichtangabe. Eine elektronische Unterschrift wird mit diesem Formular nicht erfasst.</p>
        </form>
    </main>
</x-layouts.app>
