<div class="mx-auto max-w-6xl space-y-6 px-4 py-6">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-fw-navy">{{ $associationName }} – Vereinsverwaltung</h1>
            <p class="mt-1 text-sm text-gray-600">Mitgliederdaten und SEPA-Mandate werden hier dokumentiert. Die App zieht keine Beiträge ein.</p>
            @if (config('vereinsverwaltung.creditor_id'))
                <p class="mt-1 text-sm text-gray-600">Gläubiger-Identifikationsnummer: {{ config('vereinsverwaltung.creditor_id') }}</p>
            @endif
        </div>
        <button type="button" wire:click="newMember" class="rounded bg-fw-navy px-4 py-2 font-semibold text-white">Mitglied hinzufügen</button>
    </header>

    @if (session('flash'))
        <div role="status" class="rounded-lg bg-green-50 p-4 text-green-900">{{ session('flash') }}</div>
    @endif

    <nav class="flex gap-2 border-b border-gray-200" aria-label="Vereinsverwaltung">
        <button type="button" wire:click="$set('tab', 'applications')" class="border-b-2 px-3 py-2 {{ $tab === 'applications' ? 'border-fw-navy font-semibold text-fw-navy' : 'border-transparent text-gray-600' }}">
            Beitrittsanträge ({{ $applications->count() }})
        </button>
        <button type="button" wire:click="$set('tab', 'members')" class="border-b-2 px-3 py-2 {{ $tab === 'members' ? 'border-fw-navy font-semibold text-fw-navy' : 'border-transparent text-gray-600' }}">
            Mitglieder
        </button>
    </nav>

    @if ($tab === 'applications')
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Offene Beitrittsanträge</h2>
            @forelse ($applications as $application)
                <article wire:key="application-{{ $application->id }}" class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-gray-200 p-4">
                    <div>
                        <h3 class="font-semibold">{{ $application->displayName() }}</h3>
                        <p class="text-sm text-gray-700">{{ $application->email }} · eingegangen {{ $application->submitted_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</p>
                        <p class="mt-1 text-xs text-gray-600">
                            {{ $application->youth_member ? 'Jugend-/Kinderfeuerwehr: beitragsfrei, ohne Bankdaten oder SEPA-Mandat.' : 'Personen- und Bankdaten sind verschlüsselt gespeichert. Die Bankverbindung wird nach Annahme als Mandat „Unterschrift ausstehend“ übernommen.' }}
                            Fotoeinwilligung: {{ $application->photo_consent ? 'erteilt' : 'nicht erteilt' }}{{ $application->youth_photo_consent ? ' (zusätzliche Zustimmung des Jugendlichen dokumentiert)' : '' }}.
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" wire:click="approve({{ $application->id }})" wire:confirm="Antrag annehmen und als {{ $application->youth_member ? 'kostenfreies Jugend-/Kinderfeuerwehrmitglied' : 'passives Mitglied' }} anlegen?" class="rounded bg-green-700 px-3 py-2 text-sm font-semibold text-white">Annehmen</button>
                        <button type="button" wire:click="reject({{ $application->id }})" wire:confirm="Antrag ablehnen und alle persönlichen Angaben unwiderruflich löschen?" class="rounded border border-red-700 px-3 py-2 text-sm font-semibold text-red-800">Ablehnen</button>
                    </div>
                </article>
            @empty
                <p class="rounded-lg bg-gray-50 p-4 text-gray-700">Es gibt keine offenen Beitrittsanträge.</p>
            @endforelse
        </section>
    @else
        <section class="space-y-4">
            <h2 class="text-lg font-semibold">Mitglieder</h2>
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="px-4 py-3">Mitgliedsart</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Jahresbeitrag</th>
                            <th scope="col" class="px-4 py-3">SEPA-Mandat</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Aktionen</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @forelse ($members as $member)
                            <tr wire:key="member-{{ $member->id }}">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ $member->displayName() }}</div>
                                    @if ($member->user)
                                        <div class="text-xs text-gray-600">App-Konto verknüpft</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    {{ $member->youth_member ? 'Jugend-/Kinderfeuerwehr' : ($member->membership_type === 'active' ? 'Aktiv' : 'Passiv') }}
                                </td>
                                <td class="px-4 py-3">{{ $member->status === 'member' ? 'Mitglied' : 'Ausgetreten' }}</td>
                                <td class="px-4 py-3">{{ number_format($member->annual_fee_cents / 100, 2, ',', '.') }} €</td>
                                <td class="px-4 py-3">{{ match ($member->mandate_status) { 'documented' => 'Dokumentiert', 'revoked' => 'Widerrufen', 'not_required' => 'Nicht erforderlich', default => 'Unterschrift ausstehend' } }}</td>
                                <td class="px-4 py-3 text-right"><button type="button" wire:click="edit({{ $member->id }})" class="font-semibold text-fw-navy underline">Bearbeiten</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-gray-600">Noch keine Mitglieder erfasst.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $members->links() }}
        </section>
    @endif

    @if ($memberId || $creating)
        <div class="fixed inset-0 z-40 overflow-y-auto bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="member-editor-title">
            <section class="mx-auto my-8 max-w-3xl space-y-5 rounded-xl bg-white p-5 shadow-xl">
                <header class="flex items-center justify-between gap-3">
                    <h2 id="member-editor-title" class="text-xl font-bold">{{ $creating ? 'Mitglied hinzufügen' : 'Mitglied bearbeiten' }}</h2>
                    <button type="button" wire:click="closeEditor" class="rounded px-3 py-2 text-gray-700">Schließen</button>
                </header>

                <form x-data="{ youth: @js((bool) ($form['youth_member'] ?? false)) }" wire:submit="save" class="space-y-6">
                    <fieldset class="space-y-4">
                        <legend class="font-semibold">Mitgliedschaft</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="flex items-start gap-3 sm:col-span-2">
                                <input type="checkbox" wire:model="form.youth_member" x-model="youth" class="mt-1 rounded border-gray-300">
                                <span>Mitglied der Jugend-/Kinderfeuerwehr (beitragsfrei, ohne SEPA-Mandat)</span>
                            </label>
                            <label class="block">Mitgliedsart
                                <select wire:model="form.membership_type" class="mt-1 block w-full rounded border-gray-300">
                                    <option value="active">Aktiv</option><option value="passive">Passiv</option>
                                </select>
                            </label>
                            <label class="block">Status
                                <select wire:model="form.status" class="mt-1 block w-full rounded border-gray-300">
                                    <option value="member">Mitglied</option><option value="left">Ausgetreten</option>
                                </select>
                            </label>
                            <label class="block sm:col-span-2">Verknüpftes Kameradenkonto
                                <select wire:model="form.user_id" class="mt-1 block w-full rounded border-gray-300">
                                    <option value="">Kein App-Konto</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">Beitrittsdatum <input type="date" wire:model="form.joined_at" required class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Austrittsdatum <input type="date" wire:model="form.left_at" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Jahresbeitrag in Euro <input type="number" min="0" max="100000" step="0.01" wire:model="form.annual_fee" x-bind:disabled="youth" required class="mt-1 block w-full rounded border-gray-300"></label>
                            @error('form.annual_fee')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                        </div>
                    </fieldset>

                    <fieldset class="space-y-4">
                        <legend class="font-semibold">Persönliche Angaben</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <label class="block">Titel <input wire:model="form.title" maxlength="30" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Vorname * <input wire:model="form.first_name" required maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Nachname * <input wire:model="form.last_name" required maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Geburtsdatum <input type="date" wire:model="form.birth_date" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Familienstand <input wire:model="form.marital_status" maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Telefon <input wire:model="form.phone" maxlength="50" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Mobiltelefon <input wire:model="form.mobile" maxlength="50" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">E-Mail <input type="email" wire:model="form.email" maxlength="254" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block sm:col-span-2">Straße und Hausnummer <input wire:model="form.street" maxlength="255" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Postleitzahl <input wire:model="form.postal_code" maxlength="20" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Wohnort <input wire:model="form.city" maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-4">
                        <legend class="font-semibold">SEPA-Mandat – Dokumentation</legend>
                        <p class="text-sm text-gray-700">Bank- und Mandatsdaten werden verschlüsselt gespeichert. Die App erzeugt keine Lastschriften oder Dateien und führt keine Zahlungen aus.</p>
                        <p x-cloak x-show="youth" class="font-medium text-gray-700">Für Jugend- und Kinderfeuerwehrmitglieder nicht erforderlich.</p>
                        <div x-cloak x-show="!youth" class="grid gap-4 sm:grid-cols-2">
                            <label class="block">Mandatsstatus
                                <select wire:model="form.mandate_status" class="mt-1 block w-full rounded border-gray-300">
                                    <option value="awaiting_signature">Unterschrift ausstehend</option>
                                    <option value="documented">Mandat dokumentiert</option>
                                    <option value="revoked">Widerrufen</option>
                                    <option value="not_required">Nicht erforderlich (Jugend-/Kinderfeuerwehr)</option>
                                </select>
                            </label>
                            <label class="block">Unterschriftsdatum <input type="date" wire:model="form.mandate_signed_at" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Mandatsreferenz <input wire:model="form.mandate_reference" maxlength="100" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Kontoinhaber <input wire:model="form.account_holder" maxlength="200" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">Kreditinstitut <input wire:model="form.bank_name" maxlength="200" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block">BIC <input wire:model="form.bic" maxlength="11" class="mt-1 block w-full rounded border-gray-300"></label>
                            <label class="block sm:col-span-2">IBAN <input wire:model="form.iban" maxlength="34" class="mt-1 block w-full rounded border-gray-300"></label>
                        </div>
                        @foreach (['user_id', 'membership_type', 'status', 'title', 'first_name', 'last_name', 'birth_date', 'marital_status', 'phone', 'mobile', 'email', 'street', 'postal_code', 'city', 'joined_at', 'left_at', 'annual_fee_cents', 'mandate_status', 'account_holder', 'bank_name', 'bic', 'iban', 'mandate_reference', 'mandate_signed_at'] as $field)
                            @error($field)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                        @endforeach
                    </fieldset>

                    <fieldset class="space-y-2 rounded border border-gray-200 p-4">
                        <legend class="font-semibold">Fotoeinwilligung</legend>
                        @if ($form['photo_consent'] ?? false)
                            <p>Erziehungsberechtigte beziehungsweise Mitglied haben der Foto-/Videoveröffentlichung zugestimmt.</p>
                        @else
                            <p>Keine Einwilligung zur Foto-/Videoveröffentlichung erfasst.</p>
                        @endif
                        @if ($form['youth_photo_consent'] ?? false)
                            <p>Zusätzliche Zustimmung des Jugendlichen ab 14 Jahren erfasst.</p>
                        @endif
                        @if ($form['photo_consent_recorded_at'] ?? false)
                            <p class="text-sm text-gray-600">Im Online-Antrag dokumentiert am {{ $form['photo_consent_recorded_at'] }}.</p>
                        @endif
                    </fieldset>

                    <div class="flex justify-end gap-3">
                        <button type="button" wire:click="closeEditor" class="rounded border border-gray-300 px-4 py-2">Abbrechen</button>
                        <button type="submit" class="rounded bg-fw-navy px-4 py-2 font-semibold text-white">Speichern</button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
