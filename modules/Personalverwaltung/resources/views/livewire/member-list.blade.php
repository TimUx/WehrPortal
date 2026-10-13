@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Personalverwaltung</h1>
        <p class="text-sm text-gray-500">Laufbahn, Ehrungen, Dienstausweis und Dienstbeteiligung je Kamerad.@if($scoped) Du siehst die Kameraden deiner Abteilungen.@endif</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder E-Mail" class="min-w-0 flex-1 {{ $input }}">
        <select wire:model.live="groupId" class="{{ $input }}"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $input }}"><option value="active">Aktiv</option><option value="inactive">Inaktiv</option><option value="left">Ausgetreten</option><option value="">Alle</option></select>
    </div>

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead><tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                <th class="px-4 py-2">Name</th><th class="px-4 py-2">Abteilungen</th><th class="px-4 py-2">Dienstgrad</th><th class="px-4 py-2 text-right">Dienstjahre</th>
                @if($withAttendance)<th class="px-4 py-2 text-right">Beteiligung {{ $year }}</th>@endif
                <th class="px-4 py-2"></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($members as $member)
                    <tr wire:key="m-{{ $member->id }}" class="hover:bg-gray-50">
                        <td class="px-4 py-2"><a href="{{ route('personalverwaltung.member', $member) }}" class="font-medium text-gray-900 hover:underline">{{ $member->name }}</a></td>
                        <td class="px-4 py-2 text-gray-600">{{ $member->groups->pluck('name')->implode(', ') ?: '–' }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $member->rank ?: '–' }}</td>
                        <td class="px-4 py-2 text-right text-gray-600">{{ \Modules\Personalverwaltung\src\Services\PersonnelService::serviceYears($member) ?? '–' }}</td>
                        @if($withAttendance)
                            @php($a = $attendance[$member->id] ?? null)
                            <td class="px-4 py-2 text-right text-gray-600">{{ $a && $a['percent'] !== null ? $a['percent'].' % ('.$a['attended'].'/'.$a['total'].')' : '–' }}</td>
                        @endif
                        <td class="px-4 py-2 text-right">@if(isset($due[$member->id]))<span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Ehrung fällig</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Keine Kameraden gefunden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $members->links() }}
</div>
