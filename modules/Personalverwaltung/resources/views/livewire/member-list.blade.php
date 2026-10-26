@php($input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red')
<div class="space-y-4">
    <div>
        <h1 class="text-xl font-bold text-gray-900">Personalverwaltung</h1>
        <p class="text-sm text-gray-500">Laufbahn, Ehrungen, Dienstausweis und Dienstbeteiligung je Kamerad.@if($scoped) Du siehst die Kameraden deiner Abteilungen.@endif</p>
        @if($canEdit && \Modules\Personalverwaltung\src\Services\PersonnelService::activeFields())
            <a href="{{ route('personalverwaltung.addresses') }}" class="mt-1 inline-block text-sm text-fw-navy underline">Adressliste (CSV)</a>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name oder E-Mail" class="min-w-0 flex-1 {{ $input }}">
        <select wire:model.live="groupId" class="{{ $input }}"><option value="">Alle Abteilungen</option>@foreach($groups as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
        <select wire:model.live="status" class="{{ $input }}"><option value="active">Aktiv</option><option value="inactive">Inaktiv</option><option value="left">Ausgetreten</option><option value="">Alle</option></select>
    </div>

    @if($flash)<p class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ $flash }}</p>@endif
    @if($canEdit && $selected)
        <section class="space-y-2 rounded-2xl border border-fw-navy bg-white p-4 shadow-sm">
            <p class="text-sm font-semibold text-gray-900">{{ count($selected) }} ausgewählt – Sammelverarbeitung</p>
            <div class="flex flex-wrap items-end gap-2">
                <select wire:model.live="bulkAction" class="{{ $input }}" aria-label="Aktion">
                    <option value="promote">Befördern</option><option value="honor">Ehrung eintragen</option>
                    <option value="function_add">Funktion zuweisen</option><option value="function_remove">Funktion entfernen</option>
                </select>
                @if($bulkAction === 'promote')
                    <select wire:model="bulkStep" class="{{ $input }}" aria-label="Dienstgrad"><option value="">eine Stufe höher</option>@foreach($steps as $s => $label)<option value="{{ $s }}">auf {{ $label }}</option>@endforeach</select>
                @elseif($bulkAction === 'honor')
                    <select wire:model="bulkHonorType" class="{{ $input }}" aria-label="Ehrung"><option value="">– Ehrung wählen –</option>@foreach($honorTypes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    <input type="text" wire:model="bulkHonorTitle" maxlength="200" placeholder="oder frei benennen" class="{{ $input }}">
                @else
                    <select wire:model="bulkFunction" class="{{ $input }}" aria-label="Funktion"><option value="">– Funktion wählen –</option>@foreach($functions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                @endif
                @if(in_array($bulkAction, ['promote', 'honor'], true))<input type="date" wire:model="bulkDate" class="{{ $input }}" aria-label="am">@endif
                <button type="button" wire:click="applyBulk" wire:confirm="Für {{ count($selected) }} Kameraden ausführen?" class="rounded-lg bg-fw-navy px-4 py-2 text-sm font-semibold text-white">Ausführen</button>
                <button type="button" wire:click="$set('selected', [])" class="py-2 text-sm text-gray-500 underline">Auswahl aufheben</button>
            </div>
            <p class="text-xs text-gray-500">Befördert wird in der Form des Kameraden (z. B. Oberfeuerwehrmann / Oberfeuerwehrfrau) mit dem gewählten Datum in der Diensthistorie. Wer schon so weit ist oder die Ehrung schon hat, wird übersprungen.</p>
            @error('bulk')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
        </section>
    @endif

    <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead><tr class="border-b border-gray-100 text-left text-xs uppercase tracking-wide text-gray-500">
                <th class="px-2 py-2"></th><th class="px-4 py-2">Name</th><th class="px-4 py-2">Abteilungen</th><th class="px-4 py-2">Dienstgrad</th><th class="px-4 py-2">Funktionen · Qualifikationen</th><th class="px-4 py-2 text-right">Dienstjahre</th>
                @if($withAttendance)<th class="px-4 py-2 text-right">Beteiligung {{ $year }}</th>@endif
                <th class="px-4 py-2"></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($members as $member)
                    <tr wire:key="m-{{ $member->id }}" class="hover:bg-gray-50">
                        <td class="px-2 py-2">@if($canEdit)<input type="checkbox" wire:model.live="selected" value="{{ $member->id }}" class="rounded" aria-label="{{ $member->name }} auswählen">@endif</td>
                        <td class="px-4 py-2"><a href="{{ route('personalverwaltung.member', $member) }}" class="font-medium text-gray-900 hover:underline">{{ $member->name }}</a></td>
                        <td class="px-4 py-2 text-gray-600">{{ $member->groups->pluck('display_name')->implode(', ') ?: '–' }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $member->rank ?: '–' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600">{{ $member->functions->map->fullLabel()->implode(', ') ?: '–' }}
                            @if($badges[$member->id] ?? null)<span class="mt-0.5 flex flex-wrap gap-1">@foreach($badges[$member->id] as $b)<span class="rounded bg-fw-navy/10 px-1.5 py-0.5 text-[10px] font-semibold text-fw-navy">{{ $b }}</span>@endforeach</span>@endif</td>
                        <td class="px-4 py-2 text-right text-gray-600">{{ \Modules\Personalverwaltung\src\Services\PersonnelService::serviceYears($member) ?? '–' }}</td>
                        @if($withAttendance)
                            @php($a = $attendance[$member->id] ?? null)
                            <td class="px-4 py-2 text-right text-gray-600">{{ $a && $a['percent'] !== null ? $a['percent'].' % ('.$a['attended'].'/'.$a['total'].')' : '–' }}</td>
                        @endif
                        <td class="px-4 py-2 text-right">@if(isset($due[$member->id]))<span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Ehrung fällig</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Keine Kameraden gefunden.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $members->links() }}
</div>
