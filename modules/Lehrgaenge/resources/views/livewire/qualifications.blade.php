<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Ausbildungsstand</h1>
            <p class="text-sm text-gray-500">Wer welchen Lehrgang absolviert hat – aus der App, nachgetragen oder importiert. Gelb: Auffrischung in den nächsten 60 Tagen, rot: Auffrischung überfällig.</p>
        </div>
        <div class="flex items-center gap-2 print:hidden">
            <select wire:model.live="groupId" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm" aria-label="Abteilung"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
            <a href="{{ route('lehrgaenge.qualifications.csv', ['abteilung' => $groupId]) }}" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">CSV</a>
            <button type="button" onclick="window.print()" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700">Drucken</button>
        </div>
    </div>
    @if($courses->isEmpty())
        <p class="rounded-2xl border border-gray-100 bg-white p-4 text-sm text-gray-500">Noch keine absolvierten Lehrgänge.</p>
    @else
        <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
            <table class="min-w-full text-xs">
                <thead class="bg-gray-50 text-left text-gray-500"><tr><th class="sticky left-0 bg-gray-50 p-2">Kamerad</th>@foreach($courses as $c)<th class="p-2 align-bottom"><span class="block max-w-[8rem]">{{ $c->name }}</span>@if($c->repeat_months)<span class="block font-normal">alle {{ $c->repeat_months }} Mon.</span>@endif</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($members as $m)
                        <tr><td class="sticky left-0 bg-white p-2 font-medium text-gray-900"><a href="{{ route('lehrgaenge.member', $m->id) }}" class="hover:underline">{{ $m->name }}</a></td>
                            @foreach($courses as $c)
                                @php($r = $cells[$m->id.'-'.$c->id] ?? null)
                                @php($state = $r?->refreshState())
                                <td class="p-2 text-center {{ $state === 'expired' ? 'bg-red-50 text-red-700' : ($state === 'soon' ? 'bg-amber-50 text-amber-800' : 'text-green-700') }}">{{ $r ? '✓ '.$r->completed_on?->format('m/Y') : '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
