<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Zugriff</h1>
        <p class="text-sm text-gray-500">Welche Abteilungen ein Personalverwalter sieht – z. B. der Jugendwart nur die Jugendfeuerwehr. Ohne Haken sieht er alle. Admins sehen immer alle. Die Rolle „Personalverwalter“ vergibst du unter Admin → Module.</p>
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @error('scope')<p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</p>@enderror
    @forelse($managers as $manager)
        <section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm" wire:key="s-{{ $manager->id }}">
            <p class="font-semibold text-gray-900">{{ $manager->name }}</p>
            <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                @foreach($groups as $id => $name)
                    <label class="flex items-center gap-2"><input type="checkbox" value="{{ $id }}" wire:model="scopes.{{ $manager->id }}" class="rounded border-gray-300 text-fw-red focus:ring-fw-red"> {{ $name }}</label>
                @endforeach
            </div>
            <button type="button" wire:click="save({{ $manager->id }})" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Speichern</button>
        </section>
    @empty
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch niemand hat die Rolle „Personalverwalter“.</p>
    @endforelse
</div>
