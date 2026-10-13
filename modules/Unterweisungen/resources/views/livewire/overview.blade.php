@php($cls = ['ok' => 'bg-green-50 text-green-700', 'soon' => 'bg-amber-50 text-amber-800', 'expired' => 'bg-red-50 text-red-700', 'missing' => 'bg-red-50 text-red-700'])
<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Unterweisungen</h1>
            <p class="text-sm text-gray-500">Grün gültig, gelb läuft in 30 Tagen ab, rot abgelaufen oder fehlt – farbig nur, wo die Unterweisung verpflichtend ist.</p>
        </div>
        <div class="flex items-center gap-2 print:hidden">
            <select wire:model.live="groupId" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Abteilung"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
            <a href="{{ route('unterweisungen.new') }}" class="rounded-lg bg-fw-navy px-3 py-1.5 text-sm font-semibold text-white">Unterweisung durchführen</a>
            <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Drucken</button>
        </div>
    </div>
    @if($topics->isEmpty())
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine Arten – unter „Arten“ anlegen.</p>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="sticky left-0 bg-gray-50 p-2">Kamerad</th>@foreach($topics as $t)<th class="p-2 align-bottom">{{ $t->short ?: $t->name }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($users as $u)
                        <tr><td class="sticky left-0 bg-white p-2 font-medium text-gray-900">{{ $u->name }}</td>
                            @foreach($topics as $t)
                                @php($c = $cells[$u->id][$t->id])
                                <td class="p-2 text-center {{ $c['required'] ? $cls[$c['state']] : 'text-gray-400' }}">{{ $c['last'] ? $c['last']->format('m/y') : ($c['required'] ? 'fehlt' : '') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <section>
        <h2 class="mb-2 text-sm font-semibold text-gray-700">Zuletzt durchgeführt</h2>
        <ul class="divide-y divide-gray-100 rounded-2xl border border-gray-100 bg-white text-sm shadow-sm">
            @forelse($sessions as $s)
                <li class="flex flex-wrap justify-between gap-2 p-3"><a href="{{ route('unterweisungen.session', $s) }}" class="text-fw-navy underline">{{ $s->topic->label() }}</a>
                    <span class="text-xs text-gray-500">{{ $s->held_on->format('d.m.Y') }} · {{ $s->signed_count }}/{{ $s->attendances_count }} unterschrieben</span></li>
            @empty
                <li class="p-3 text-gray-500">Noch keine.</li>
            @endforelse
        </ul>
    </section>
</div>
