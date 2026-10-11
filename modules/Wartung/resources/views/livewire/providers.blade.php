@php($input = 'mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-fw-red focus:ring-fw-red')
@php($money = fn ($c) => \App\Support\Money::format($c))
<div class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Fachfirmen</h1>
            <p class="text-sm text-gray-500">Externe Dienstleister für Prüfungen und Wartungen, z. B. Atemschutzwerkstatt, Leiterprüfung, Prüfung elektrischer Geräte. Ihr Prüfer unterschreibt das Protokoll vor Ort, ihr Prüfbericht kommt als Anhang dazu.</p>
        </div>
        @if($editing === null)<button type="button" wire:click="edit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white hover:bg-fw-red-dark">Neue Fachfirma</button>@endif
    </div>
    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif

    @if($editing !== null)
        <form wire:submit="save" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
            <label class="block text-sm text-gray-700">Name<input type="text" wire:model="form.name" maxlength="150" class="{{ $input }}"></label>
            @error('provider_name')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="block text-sm text-gray-700">Ansprechpartner<input type="text" wire:model="form.contact_person" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">E-Mail<input type="email" wire:model="form.email" maxlength="150" class="{{ $input }}"></label>
                <label class="block text-sm text-gray-700">Telefon<input type="text" wire:model="form.phone" maxlength="50" class="{{ $input }}"></label>
            </div>
            @error('provider_email')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <label class="block text-sm text-gray-700">Notizen (z. B. Vertrag, Kundennummer)<textarea wire:model="form.notes" rows="2" class="{{ $input }}"></textarea></label>
            <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="form.is_active" class="rounded"> Aktiv</label>
            @error('wartung')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
            <div class="flex gap-3"><button type="submit" class="rounded-lg bg-fw-red px-4 py-2 text-sm font-semibold text-white">Speichern</button><button type="button" wire:click="cancel" class="text-sm text-gray-500 underline">Abbrechen</button></div>
        </form>
    @endif

    <div class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white shadow-sm">
        @forelse($providers as $p)
            <div class="flex items-center justify-between gap-3 p-3 text-sm" wire:key="p-{{ $p->id }}">
                <div class="min-w-0">
                    <p class="font-medium {{ $p->is_active ? 'text-gray-900' : 'text-gray-400' }}">{{ $p->name }}{{ $p->is_active ? '' : ' (inaktiv)' }}</p>
                    <p class="text-xs text-gray-500">{{ collect([$p->contact_person, $p->email, $p->phone])->filter()->implode(' · ') ?: 'keine Kontaktdaten' }}</p>
                    @if($s = $stats[$p->id] ?? null)<p class="text-xs text-gray-500">{{ $s->n }} Protokolle, zuletzt {{ \Illuminate\Support\Carbon::parse($s->last)->format('d.m.Y') }}, Rechnungen {{ $money((int) $s->cost) }}</p>@endif
                </div>
                <button type="button" wire:click="edit({{ $p->id }})" class="text-xs text-fw-navy underline">ändern</button>
            </div>
        @empty
            <p class="p-4 text-sm text-gray-500">Noch keine Fachfirmen.</p>
        @endforelse
    </div>
</div>
