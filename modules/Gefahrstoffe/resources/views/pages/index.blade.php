<x-layouts.app title="Gefahrstoffdatenbank">
    @php($pictograms = \Modules\Gefahrstoffe\src\Data\GhsPictograms::all())
    <div class="mx-auto max-w-4xl space-y-5" x-data="hazardousMaterials">
        <div class="flex flex-wrap items-start justify-between gap-3" x-data="referenceOffline">
            <div>
                <h1 class="text-xl font-bold text-gray-900">Gefahrstoffdatenbank</h1>
                <p class="mt-1 text-sm text-gray-600">Standortübergreifende Referenzdaten. Quelle und Datenstand stehen bei jedem importierten Eintrag.</p>
            </div>
            <button type="button" x-on:click="save()" :disabled="saving"
                class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-fw-navy disabled:opacity-50">
                <span x-text="saving ? 'Wird gespeichert …' : 'Für offline speichern'"></span>
            </button>
            <p class="basis-full text-sm text-gray-600" role="status" x-text="message"></p>
        </div>

        <section aria-labelledby="ghs-heading" class="space-y-3">
            <div>
                <h2 id="ghs-heading" class="text-lg font-semibold text-gray-900">GHS-Gefahrenpiktogramme</h2>
                <p class="mt-1 text-sm text-gray-600">Allgemeine Übersicht der neun standardisierten Piktogramme nach dem Global Harmonisierten System (GHS).</p>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($pictograms as $pictogram)
                    <article class="flex items-start gap-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" aria-label="{{ $pictogram['code'] }}: {{ $pictogram['label'] }}">
                        @include('gefahrstoffe::components.ghs-pictogram', ['code' => $pictogram['code']])
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">{{ $pictogram['code'] }} · {{ $pictogram['label'] }}</p>
                            <p class="mt-1 text-sm text-gray-600">{{ $pictogram['description'] }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
            <p class="text-xs text-gray-500">Die Übersicht dient der Orientierung und ersetzt weder Kennzeichnung noch Sicherheitsdatenblatt oder Einsatzbeurteilung.</p>
        </section>

        <h2 class="pt-2 text-lg font-semibold text-gray-900">Importierter Gefahrstoffkatalog</h2>
        <label class="block">
            <span class="sr-only">Gefahrstoff suchen</span>
            <input type="search" x-model.debounce.150ms="query" placeholder="UN-Nummer, Stoffname oder Synonym"
                class="w-full rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm shadow-sm">
        </label>

        <p x-show="loading" class="text-sm text-gray-500">Datenbank wird geladen …</p>
        <p x-show="error" x-text="error" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900" role="status"></p>
        <p x-show="!loading && !error && filtered.length === 0" class="rounded-xl border border-gray-100 bg-white p-4 text-sm text-gray-500">
            <span x-show="items.length === 0">Noch keine Gefahrstoffdaten importiert.</span>
            <span x-show="items.length > 0">Keine passenden Stoffe gefunden.</span>
        </p>

        <div class="space-y-3">
            <template x-for="material in filtered" :key="material.un_number">
                <details class="rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <summary class="cursor-pointer list-none">
                        <span class="font-semibold text-gray-900">UN <span x-text="material.un_number"></span> · <span x-text="material.name"></span></span>
                        <span class="mt-1 block text-xs text-gray-600">
                            <span x-show="material.adr_class">ADR <span x-text="material.adr_class"></span></span>
                            <span x-show="material.packing_group"> · VG <span x-text="material.packing_group"></span></span>
                            <span x-show="material.hazard_number"> · Gefahrnummer <span x-text="material.hazard_number"></span></span>
                        </span>
                    </summary>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <template x-for="[label, field] in sections" :key="field">
                            <div x-show="material[field]">
                                <dt class="font-semibold text-gray-800" x-text="label"></dt>
                                <dd class="mt-1 whitespace-pre-line text-gray-700" x-text="format(material[field])"></dd>
                            </div>
                        </template>
                        <div x-show="material.hazard_labels?.length">
                            <dt class="font-semibold text-gray-800">Gefahrzettel</dt>
                            <dd class="mt-1 text-gray-700" x-text="material.hazard_labels?.join(', ')"></dd>
                        </div>
                        <div x-show="material.ghs_pictograms?.length">
                            <dt class="font-semibold text-gray-800">GHS-Piktogramme</dt>
                            <dd class="mt-1 text-gray-700" x-text="material.ghs_pictograms?.join(', ')"></dd>
                        </div>
                        <div class="sm:col-span-2 text-xs text-gray-500">
                            Quelle: <span x-text="material.source_name"></span>
                            <span x-show="material.source_version"> · Datenstand: <span x-text="material.source_version"></span></span>
                            <span x-show="material.imported_at"> · Import: <span x-text="new Date(material.imported_at).toLocaleDateString('de-DE')"></span></span>
                            <span x-show="material.source_reference"> · <span x-text="material.source_reference"></span></span>
                        </div>
                    </dl>
                </details>
            </template>
        </div>
        <p x-show="loadedAt" class="text-xs text-gray-500">Katalog geladen: <span x-text="loadedAt"></span></p>
    </div>
</x-layouts.app>
