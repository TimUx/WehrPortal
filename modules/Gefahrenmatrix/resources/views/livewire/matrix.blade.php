<div class="mx-auto max-w-4xl space-y-6" x-data="hazardMatrix(@js($groups))">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Gefahrenmatrix</h1>
        <p class="mt-1 text-sm text-gray-600">AAAA – CCCC – EEEE: Gefahren an der Einsatzstelle als Merkstütze erfassen.</p>
        <button type="button" x-data="referenceOffline" x-on:click="save()" :disabled="saving"
            class="mt-3 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-fw-navy disabled:opacity-50">
            <span x-text="saving ? 'Wird gespeichert …' : 'Für offline speichern'"></span>
            <span class="sr-only" role="status" x-text="message"></span>
        </button>
    </div>

    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950" role="note">
        Diese Auswahl ist eine nicht gespeicherte Merkstütze. Sie leitet keine Schutzgüter, Maßnahmen oder Einsatzentscheidungen ab. Die fachliche Beurteilung bleibt der Einsatzleitung vorbehalten.
    </div>

    <template x-for="[groupKey, group] in Object.entries(groups)" :key="groupKey">
        <section class="space-y-3">
            <h2 class="text-base font-semibold text-gray-900" x-text="group.title"></h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <template x-for="[key, label] in Object.entries(group.hazards)" :key="key">
                    <button
                        type="button"
                        x-on:click="toggle(key)"
                        :aria-pressed="selected.includes(key)"
                        :class="selected.includes(key) ? 'border-fw-navy bg-fw-navy text-white' : 'border-gray-200 bg-white text-gray-800 hover:border-fw-navy'"
                        class="min-h-14 rounded-xl border px-4 py-3 text-left text-sm font-semibold transition"
                    >
                        <span x-text="label"></span>
                    </button>
                </template>
            </div>
        </section>
    </template>

    <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm" aria-live="polite">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-gray-900">Ausgewählte Gefahren</h2>
            <button type="button" x-on:click="clear" class="rounded-lg px-3 py-2 text-sm font-semibold text-fw-navy underline" :disabled="selected.length === 0">
                Auswahl zurücksetzen
            </button>
        </div>
        <p x-show="selected.length === 0" class="mt-3 text-sm text-gray-500">Noch keine Gefahren ausgewählt.</p>
        <ul x-show="selected.length > 0" class="mt-3 flex flex-wrap gap-2">
            <template x-for="key in selected" :key="key">
                <li class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-800" x-text="label(key)"></li>
            </template>
        </ul>
    </section>
</div>
