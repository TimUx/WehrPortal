{{-- Personalverwaltung: the Unterweisungen of $member --}}
@php($uwRows = app(\Modules\Unterweisungen\src\Services\InstructionService::class)->forUser($member))
<section class="space-y-2 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
    <h2 class="text-sm font-semibold text-gray-900">Unterweisungen</h2>
    <ul class="divide-y divide-gray-100 text-sm">
        @forelse($uwRows as $r)
            <li class="flex flex-wrap justify-between gap-2 py-1"><span>{{ $r['topic']->label() }}</span>
                <span class="text-xs {{ ['ok' => 'text-green-700', 'soon' => 'text-amber-700', 'expired' => 'text-red-700', 'missing' => 'text-red-700'][$r['state']] }}">{{ \Modules\Unterweisungen\src\Services\InstructionService::STATES[$r['state']] }}{{ $r['last'] ? ' · '.$r['last']->format('d.m.Y') : '' }}</span></li>
        @empty
            <li class="py-1 text-gray-500">Keine.</li>
        @endforelse
    </ul>
</section>
