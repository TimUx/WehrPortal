{{-- Keys a Kamerad has, e.g. in the Personalverwaltung: $member --}}
@php($heldBy = \Modules\Schluessel\src\Models\Handover::with('key')->where('user_id', $member->id)->whereNull('returned_at')->orderBy('issued_at')->get())
<section class="{{ $class ?? 'space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm' }}">
    <h2 class="text-sm font-semibold text-gray-900">Schlüssel</h2>
    <ul class="divide-y divide-gray-100 text-sm">
        @forelse($heldBy as $h)
            <li class="flex flex-wrap justify-between gap-2 py-1.5">
                <span class="font-medium text-gray-900">{{ $h->key->name }} <span class="font-normal text-gray-500">· {{ $h->key->code }}</span></span>
                <span class="text-xs {{ $h->overdue() ? 'font-semibold text-red-700' : 'text-gray-500' }}">seit {{ $h->issued_at->setTimezone('Europe/Berlin')->format('d.m.Y') }}@if($h->due_on) · bis {{ $h->due_on->format('d.m.Y') }}@endif</span>
            </li>
        @empty
            <li class="py-1.5 text-gray-500">Keine Schlüssel.</li>
        @endforelse
    </ul>
</section>
