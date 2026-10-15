@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Alle Schlüssel</h1>
        <x-page-action :href="route('schluessel.create')">Neuer Schlüssel</x-page-action>
    </div>
    @if($former->isNotEmpty())
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Schlüssel bei Kameraden, die nicht mehr aktiv sind</p>
            <ul class="mt-1 list-disc pl-5">@foreach($former as $h)<li><a href="{{ route('schluessel.show', $h->key) }}" class="underline">{{ $h->key->name }}</a> – {{ $h->user->name }} ({{ $h->user->status?->label() }})</li>@endforeach</ul>
        </div>
    @endif
    <div class="flex flex-wrap gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Schlüssel, Nummer, Schließung oder Name" class="min-w-0 flex-1 {{ $input }}">
        <select wire:model.live="status" class="{{ $input }}"><option value="">Alle (ohne ausgesonderte)</option><option value="issued">Ausgegeben</option><option value="available">Vorhanden</option><option value="lost">Verloren</option><option value="retired">Ausgesondert</option></select>
    </div>
    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead><tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500"><th class="px-4 py-2">Nr.</th><th class="px-4 py-2">Schlüssel</th><th class="px-4 py-2">Schließt</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Bei</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($keys as $k)
                    <tr wire:key="k-{{ $k->id }}" class="hover:bg-gray-50">
                        <td class="px-4 py-2"><a href="{{ route('schluessel.show', $k) }}" class="text-fw-navy hover:underline">{{ $k->code }}</a></td>
                        <td class="px-4 py-2"><a href="{{ route('schluessel.show', $k) }}" class="font-medium text-gray-900 hover:underline">{{ $k->name }}</a><span class="block text-xs text-gray-500">{{ $k->kindLabel() }}</span></td>
                        <td class="px-4 py-2 text-gray-600">{{ $k->opensLabel() ?: '–' }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-xs font-semibold {{ $k->status === 'lost' ? 'bg-red-100 text-red-800' : ($k->current ? 'bg-blue-100 text-blue-800' : ($k->status === 'retired' ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-800')) }}">{{ $k->statusLabel() }}</span></td>
                        <td class="px-4 py-2 text-gray-600">@if($k->current?->user)@if($url = $memberUrl($k->current->user))<a href="{{ $url }}" class="text-fw-navy hover:underline">{{ $k->current->user->name }}</a>@else{{ $k->current->user->name }}@endif @else{{ $k->storage ?: '–' }}@endif@if($k->current?->overdue())<span class="block text-xs font-semibold text-red-700">überfällig seit {{ $k->current->due_on->format('d.m.Y') }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Keine Schlüssel gefunden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
