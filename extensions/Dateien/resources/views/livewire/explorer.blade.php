@php
    $input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red';
    $button = 'inline-flex items-center rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white disabled:opacity-50';
    $link = 'text-sm font-medium text-fw-navy underline';
@endphp
<div class="w-full space-y-4" x-data="{ modal: null, menu: { open: false, x: 0, y: 0, node: null }, show(e, node) { this.menu = { open: true, x: Math.max(8, Math.min(e.clientX, window.innerWidth - 240)), y: Math.max(8, Math.min(e.clientY, window.innerHeight - 340)), node }; } }" x-on:modal-done.window="modal = null" x-on:click.window="menu.open = false" x-on:keydown.escape.window="menu.open = false; modal = null" x-on:scroll.window="menu.open = false">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Dateien</h1>
        <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto">
            <label class="min-w-48 flex-1 text-sm sm:w-72 sm:flex-none"><span class="sr-only">Dateien suchen</span>
                <input type="search" wire:model.live.debounce.400ms="search" maxlength="100" placeholder="Name suchen …" class="{{ $input }} w-full">
            </label>
            @if(($canWriteHere && ! $searching) || ($currentSpace && $view !== 'trash' && $access->can($user, $currentSpace, 'manage')))
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1" role="group" aria-label="Aktionen">
                @if($canWriteHere && ! $searching)
                    <button type="button" x-on:click="modal = 'folder'" title="Neuer Ordner" aria-label="Neuer Ordner" class="rounded-md p-1.5 text-fw-navy hover:bg-gray-100"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM12 10v5M9.5 12.5h5"/></svg></button>
                    @if($newFileTypes !== [])
                        <button type="button" x-on:click="modal = 'file'" title="Neue Datei" aria-label="Neue Datei" class="rounded-md p-1.5 text-fw-navy hover:bg-gray-100"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zM14 3v5h5M12 11v6M9 14h6"/></svg></button>
                    @endif
                    <button type="button" x-on:click="modal = 'upload'" title="Hochladen" aria-label="Hochladen" class="rounded-md p-1.5 text-fw-navy hover:bg-gray-100"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg></button>
                @endif
                @if($currentSpace && $view !== 'trash' && $access->can($user, $currentSpace, 'manage'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }}, null, 'trash')" title="Papierkorb dieses Bereichs" aria-label="Papierkorb dieses Bereichs" class="rounded-md p-1.5 text-fw-navy hover:bg-gray-100">@include('dateien::partials.icon', ['kind' => 'trash'])</button>
                @endif
            </div>
            @endif
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1" role="group" aria-label="Ansicht wählen">
                <button type="button" wire:click="setLayout('tiles')" title="Symbole" aria-label="Symbole" aria-pressed="{{ $layout === 'tiles' ? 'true' : 'false' }}" class="rounded-md p-1.5 {{ $layout === 'tiles' ? 'bg-fw-navy text-white' : 'text-fw-navy hover:bg-gray-100' }}"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1"/></svg></button>
                <button type="button" wire:click="setLayout('list')" title="Liste" aria-label="Liste" aria-pressed="{{ $layout === 'list' ? 'true' : 'false' }}" class="rounded-md p-1.5 {{ $layout === 'list' ? 'bg-fw-navy text-white' : 'text-fw-navy hover:bg-gray-100' }}"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg></button>
                <button type="button" wire:click="setLayout('table')" title="Details" aria-label="Details" aria-pressed="{{ $layout === 'table' ? 'true' : 'false' }}" class="rounded-md p-1.5 {{ $layout === 'table' ? 'bg-fw-navy text-white' : 'text-fw-navy hover:bg-gray-100' }}"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16M10 4v16"/></svg></button>
            </div>
        </div>
    </div>
    @if(session('flash'))
        <p role="status" class="rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800">{{ session('flash') }}</p>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    <div class="flex flex-col gap-4 md:flex-row md:items-start">
        @php
            $navItem = 'flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm';
            $navOn = 'bg-blue-50 font-semibold text-fw-navy';
            $navOff = 'text-gray-700 hover:bg-gray-50';
        @endphp
        <aside aria-label="Bereiche" class="w-full shrink-0 space-y-1 rounded-2xl border border-gray-100 bg-white p-2 shadow-sm md:w-72">
            @forelse($spaces as $item)
                <button type="button" wire:click="navigate({{ $item->id }})" class="{{ $navItem }} {{ $currentSpace?->id === $item->id && ! $folder && $view === '' ? $navOn : $navOff }}">
                    @include('dateien::partials.icon', ['kind' => 'space'])<span class="min-w-0 break-words">{{ $item->name }}</span>
                </button>
                @if($currentSpace?->id === $item->id && $folderTree !== [] && ! $searching)
                    @foreach($folderTree as $treeRow)
                        <button type="button" wire:click="navigate(null, {{ $treeRow['node']->id }})" style="margin-left: {{ min($treeRow['depth'] * 1 + 1, 6) }}rem" class="{{ $navItem }} w-auto max-w-full {{ $folder?->id === $treeRow['node']->id ? $navOn : $navOff }}">
                            @include('dateien::partials.icon', ['kind' => 'folder', 'class' => 'h-4 w-4'])<span class="min-w-0 truncate">{{ $treeRow['node']->name }}</span>
                        </button>
                    @endforeach
                @endif
            @empty
                <p class="px-2 py-1 text-sm text-gray-500">Keine freigegebenen Bereiche.</p>
            @endforelse
            <hr class="my-2 border-gray-100">
            <button type="button" wire:click="navigate(null, null, 'favorites')" class="{{ $navItem }} {{ $view === 'favorites' ? $navOn : $navOff }}">@include('dateien::partials.icon', ['kind' => 'star'])Favoriten</button>
            <button type="button" wire:click="navigate(null, null, 'recent')" class="{{ $navItem }} {{ $view === 'recent' ? $navOn : $navOff }}">@include('dateien::partials.icon', ['kind' => 'clock'])Zuletzt verwendet</button>
            <button type="button" wire:click="navigate(null, null, 'shared')" class="{{ $navItem }} {{ $view === 'shared' ? $navOn : $navOff }}">@include('dateien::partials.icon', ['kind' => 'share'])Mit mir geteilt</button>
            <button type="button" wire:click="navigate(null, null, 'trash')" class="{{ $navItem }} {{ $view === 'trash' && ! $currentSpace ? $navOn : $navOff }}">@include('dateien::partials.icon', ['kind' => 'trash'])Papierkorb</button>
            @if($user->is_admin)
                <details class="border-t border-gray-100 pt-2">
                    <summary class="cursor-pointer px-2 py-1 text-sm text-gray-600">Neue gemeinsame Ablage …</summary>
                    <form wire:submit="createSpace" class="space-y-2 p-2">
                        <input wire:model="spaceName" required maxlength="255" placeholder="Name der Ablage" aria-label="Name der Ablage" class="{{ $input }} w-full">
                        <button class="{{ $button }} w-full justify-center" wire:loading.attr="disabled">Ablage erstellen</button>
                    </form>
                </details>
            @endif
            @if($canManage && $view !== 'trash')
                <details x-data="{ open: false }" x-on:open-perms.window="open = true" x-bind:open="open" x-on:toggle="open = $el.open" class="border-t border-gray-100 pt-2">
<summary class="cursor-pointer px-2 py-1 text-sm font-medium text-gray-800">Berechtigungen: {{ $target->name }}</summary>
<div class="space-y-3 p-2 text-sm">
                    @if($selected)
                        <form wire:submit="saveInheritance" class="flex flex-wrap items-center gap-3">
                            <label class="text-sm"><input type="checkbox" wire:model="inheritPermissions" class="rounded border-gray-300 text-fw-red"> Berechtigungen vom übergeordneten Ordner erben</label>
                            <button class="{{ $button }}" wire:loading.attr="disabled">Speichern</button>
                        </form>
                    @endif
                    <p class="text-xs text-gray-500">Direkte Freigaben. „Entziehen“ entfernt nur diese direkte Freigabe; geerbte Rechte bleiben bestehen.</p>
                    <ul class="space-y-1 text-sm">
                        @forelse($permissions as $permission)
                            @php
                                $subjectList = match ($permission->subject_type) {
                                    'user' => $subjects['users'],
                                    'department' => $subjects['departments'],
                                    default => $subjects['groups'],
                                };
                                $subject = $subjectList->firstWhere('id', $permission->subject_id);
                            @endphp
                            <li class="flex flex-wrap items-center gap-2">
                                <span>{{ $subject?->name ?? 'Nicht verfügbarer Empfänger' }} · {{ ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'][$permission->level] ?? $permission->level }}</span>
                                <button type="button" wire:click="revokePermission({{ $permission->id }})" wire:confirm="Diese direkte Freigabe entziehen?" wire:loading.attr="disabled" class="{{ $link }}">Entziehen</button>
                            </li>
                        @empty
                            <li class="text-gray-500">Keine direkten Freigaben.</li>
                        @endforelse
                    </ul>
                    <form wire:submit="savePermission" class="flex flex-wrap items-end gap-2">
                        <label class="text-sm">Empfängertyp
                            <select wire:model.live="subjectType" class="{{ $input }} mt-1 block"><option value="user">Kamerad</option><option value="department">Abteilung</option><option value="group">Dateigruppe</option></select>
                        </label>
                        <label class="text-sm">Empfänger
                            <select wire:model="subjectId" required class="{{ $input }} mt-1 block">
                                <option value="">Bitte auswählen</option>
                                @foreach($subjects[match ($subjectType) { 'user' => 'users', 'department' => 'departments', default => 'groups' }] as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm">Berechtigung
                            <select wire:model="permissionLevel" class="{{ $input }} mt-1 block">
                                <option value="read">Lesen</option><option value="edit">Bearbeiten</option><option value="manage">Verwalten</option><option value="">Entziehen</option>
                            </select>
                        </label>
                        <button class="{{ $button }}" wire:loading.attr="disabled">Übernehmen</button>
                    </form>
                </div>
</details>
            @endif
            @if($shareNode && ($canShare || $shares->isNotEmpty()))
                <details x-data="{ open: false }" x-bind:open="open" x-on:toggle="open = $el.open" class="border-t border-gray-100 pt-2">
<summary class="cursor-pointer px-2 py-1 text-sm font-medium text-gray-800">Öffentliche Links: {{ $shareNode->name }}</summary>
<div class="space-y-3 p-2">
                    <p class="text-xs text-gray-500">Jeder mit dem Link kann {{ $shareNode->kind === 'folder' ? 'diesen Ordner samt Unterordnern ansehen und Dateien' : 'diese Datei' }} ohne Anmeldung herunterladen. Nur lesen, kein Office, keine Bearbeitung.</p>
                    @if($newShareUrl)
                        <div class="space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                            <p class="font-semibold">Link jetzt kopieren – er wird nur einmal angezeigt.</p>
                            <input type="text" readonly value="{{ $newShareUrl }}" x-on:focus="$el.select()" class="{{ $input }} w-full font-mono">
                        </div>
                    @endif
                    <ul class="space-y-1 text-sm">
                        @forelse($shares as $share)
                            <li class="flex flex-wrap items-center gap-2">
                                <span>{{ $share->label ?: 'Link' }} · bis {{ $share->expires_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}{{ $share->password_hash ? ' · Passwort' : '' }}{{ $share->max_downloads ? ' · '.$share->download_count.'/'.$share->max_downloads.' Downloads' : ' · '.$share->download_count.' Downloads' }}</span>
                                <button type="button" wire:click="revokeShare({{ $share->id }})" wire:confirm="Diesen Link widerrufen? Er funktioniert danach sofort nicht mehr." wire:loading.attr="disabled" class="{{ $link }}">Widerrufen</button>
                            </li>
                        @empty
                            <li class="text-gray-500">Keine aktiven Links.</li>
                        @endforelse
                    </ul>
                    @if($canShare)
                        <form wire:submit="createShare" class="flex flex-wrap items-end gap-2">
                            <label class="text-sm">Gültig (Tage)
                                <input type="number" wire:model="shareDays" min="1" max="{{ $shareMaxDays }}" required class="{{ $input }} mt-1 block w-24">
                            </label>
                            <label class="text-sm">Passwort (optional)
                                <input type="password" wire:model="sharePassword" minlength="8" maxlength="200" autocomplete="new-password" class="{{ $input }} mt-1 block">
                            </label>
                            <label class="text-sm">Max. Downloads (optional)
                                <input type="number" wire:model="shareMaxDownloads" min="1" max="10000" class="{{ $input }} mt-1 block w-28">
                            </label>
                            <label class="text-sm">Bezeichnung (optional)
                                <input type="text" wire:model="shareLabel" maxlength="150" class="{{ $input }} mt-1 block">
                            </label>
                            <button class="{{ $button }}" wire:loading.attr="disabled">Link erstellen</button>
                        </form>
                    @endif
                </div>
</details>
            @endif
        </aside>
        <main class="min-w-0 flex-1 space-y-4">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-gray-100 bg-white px-3 py-2 text-sm shadow-sm" aria-label="Ordnerpfad">
                @if($currentSpace && $access->can($user, $currentSpace, 'read'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }})" class="{{ $link }}">{{ $currentSpace->name }}</button>
                @elseif($view === 'shared' || $folder)
                    <button type="button" wire:click="navigate(null, null, 'shared')" class="{{ $link }}">Mit mir geteilt</button>
                @elseif($view !== 'trash')
                    <span class="text-gray-500">Bitte einen Bereich auswählen.</span>
                @endif
                @foreach($breadcrumbs as $crumb)
                    <span aria-hidden="true" class="text-gray-400">›</span>
                    <button type="button" wire:click="navigate(null, {{ $crumb->id }})" class="{{ $link }}">{{ $crumb->name }}</button>
                @endforeach
                @if($view === 'trash')<span class="font-semibold">Papierkorb</span>@endif
                @if($view === 'favorites')<span class="font-semibold">Favoriten</span>@endif
                @if($view === 'recent')<span class="font-semibold">Zuletzt verwendet</span>@endif
                @if($folder && ! $selected && $collabNode)
                    <a href="{{ route('dateien.zip', ['node' => $folder->id]) }}" class="{{ $link }}">Als ZIP herunterladen</a>
                    <button type="button" wire:click="toggleFavorite" class="{{ $link }}">{{ $isFavorite ? '★ Favorit' : '☆ Favorit' }}</button>
                    <button type="button" wire:click="toggleSubscription" class="{{ $link }}">{{ $isSubscribed ? 'Abo beenden' : 'Abonnieren' }}</button>
                @endif
            </div>
            @if($quotaWarning)
                <p role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Speicherplatz dieses Bereichs zu {{ $quotaWarning['percent'] }} % belegt ({{ number_format($quotaWarning['used'] / 1048576, 1, ',', '.') }} von {{ number_format($quotaWarning['quota'] / 1048576, 1, ',', '.') }} MB, inklusive Versionen und Papierkorb).</p>
            @endif
            @if($searching)
                <p class="text-sm text-gray-600">Suchergebnisse für „{{ $search }}“ (nur Einträge, die Sie öffnen dürfen; höchstens 50).</p>
            @endif
            @if($effectiveLevel && ! $searching)
                <p class="text-xs text-gray-500">Ihr Recht: {{ ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'][$effectiveLevel] }}</p>
            @endif
            @if($view === 'trash')
                <p class="text-sm text-gray-500">Aufbewahrungsfrist im Papierkorb: {{ $retentionDays }} Tage. Danach können Einträge endgültig gelöscht werden. Das kann nicht rückgängig gemacht werden.</p>
            @endif
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start">
            <div class="min-h-[60vh] min-w-0 flex-1 space-y-3" x-on:contextmenu.prevent="show($event, null)">
            @php
                $rows = collect($rows)->sortBy([fn ($a, $b) => ($a->kind === 'folder' ? 0 : 1) <=> ($b->kind === 'folder' ? 0 : 1), fn ($a, $b) => strnatcasecmp($a->name, $b->name)])->values();
                $open = fn ($row) => $searching ? "openResult({$row->id})" : ($row->kind === 'folder' && $view !== 'trash' ? "navigate(null, {$row->id})" : "select({$row->id})");
                $meta = fn ($row) => ($row->kind === 'folder' ? 'Ordner' : 'Datei').($row->trashed() ? ' · Gelöscht '.$row->deleted_at->timezone('Europe/Berlin')->format('d.m.Y H:i') : '');
                $ctx = fn ($row) => \Illuminate\Support\Js::from(['id' => $row->id, 'name' => $row->name, 'act' => $searching ? 'result' : ($row->kind === 'folder' && $view !== 'trash' ? 'folder' : 'file'), 'folder' => $row->kind === 'folder', 'trashed' => $row->trashed(), 'manage' => ! $row->trashed() && $access->can($user, $row, 'manage'), 'dl' => $row->trashed() ? null : ($row->kind === 'folder' ? route('dateien.zip', ['node' => $row->id]) : ($latest[$row->id] ?? null ? route('dateien.download', ['node' => $row->id, 'version' => $latest[$row->id]]) : null))]);
                $latest = \Extensions\Dateien\Models\Version::whereIn('node_id', $rows->where('kind', 'file')->pluck('id'))->selectRaw('node_id, max(id) as vid')->groupBy('node_id')->pluck('vid', 'node_id');
                $empty = $searching ? 'Keine Treffer.' : 'Dieser Ordner ist leer.';
            @endphp
            @if($layout === 'table')
                <div class="overflow-x-auto rounded-xl border border-gray-100 bg-white shadow-sm">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-gray-100 text-xs text-gray-500">
                            <tr><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Typ</th><th class="w-10 px-2 py-2"><span class="sr-only">Details</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($rows as $row)
                                <tr wire:key="node-{{ $row->id }}" x-on:contextmenu.prevent.stop="show($event, {{ $ctx($row) }})" class="hover:bg-blue-50/50 {{ $selected?->id === $row->id ? 'bg-blue-50' : '' }}">
                                    <td class="max-w-0 px-4 py-1.5">
                                        <button type="button" wire:click="{{ $open($row) }}" class="flex w-full items-center gap-2 text-left text-gray-900">@include('dateien::partials.icon', ['kind' => $row->kind === 'folder' ? 'folder' : 'file', 'ext' => pathinfo($row->name, PATHINFO_EXTENSION)])<span class="truncate">{{ $row->name }}</span></button>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-1.5 text-gray-500">{{ $meta($row) }}</td>
                                    <td class="px-2 py-1.5"><button type="button" wire:click="{{ $searching ? 'openResult' : 'select' }}({{ $row->id }})" title="Details" aria-label="Details zu {{ $row->name }}" class="rounded p-1 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-6 text-sm text-gray-500">{{ $empty }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif($layout === 'tiles')
                <div class="grid grid-cols-2 gap-2 rounded-xl border border-gray-100 bg-white p-3 shadow-sm sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    @forelse($rows as $row)
                        <button type="button" wire:key="node-{{ $row->id }}" x-on:contextmenu.prevent.stop="show($event, {{ $ctx($row) }})" wire:click="{{ $open($row) }}" title="{{ $meta($row) }}" class="flex min-w-0 flex-col items-center gap-1 rounded-lg p-3 text-center hover:bg-blue-50/50 {{ $selected?->id === $row->id ? 'bg-blue-50' : '' }}">
                            @include('dateien::partials.icon', ['kind' => $row->kind === 'folder' ? 'folder' : 'file', 'ext' => pathinfo($row->name, PATHINFO_EXTENSION), 'class' => 'h-12 w-12'])
                            <span class="w-full break-words text-sm text-gray-900">{{ $row->name }}</span>
                        </button>
                    @empty
                        <p class="col-span-full px-1 py-4 text-sm text-gray-500">{{ $empty }}</p>
                    @endforelse
                </div>
            @else
                <div class="divide-y divide-gray-50 rounded-xl border border-gray-100 bg-white shadow-sm">
                    @forelse($rows as $row)
                        <div wire:key="node-{{ $row->id }}" x-on:contextmenu.prevent.stop="show($event, {{ $ctx($row) }})" class="flex items-center gap-1 px-2 hover:bg-blue-50/50 {{ $selected?->id === $row->id ? 'bg-blue-50' : '' }}">
                            <button type="button" wire:click="{{ $open($row) }}" class="flex min-w-0 flex-1 items-center gap-3 px-2 py-2 text-left text-sm text-gray-900">
                                @include('dateien::partials.icon', ['kind' => $row->kind === 'folder' ? 'folder' : 'file', 'ext' => pathinfo($row->name, PATHINFO_EXTENSION)])
                                <span class="min-w-0 break-words">{{ $row->name }}</span>
                                @if($row->trashed())<span class="shrink-0 text-xs text-gray-500">{{ $meta($row) }}</span>@endif
                            </button>
                            <button type="button" wire:click="{{ $searching ? 'openResult' : 'select' }}({{ $row->id }})" title="Details" aria-label="Details zu {{ $row->name }}" class="rounded p-1.5 text-gray-500 hover:bg-gray-100"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg></button>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500">{{ $empty }}</p>
                    @endforelse
                </div>
            @endif
            </div>
            </div>
        </main>
    </div>
            @if($selected && $detailsOpen)
            <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" x-on:click.self="$wire.resetSelection()" x-on:keydown.escape.window="$wire.resetSelection()" role="dialog" aria-modal="true" aria-label="Details">
                <section class="max-h-[90vh] w-full max-w-2xl space-y-4 overflow-y-auto rounded-2xl bg-white p-5 shadow-xl">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="break-words font-bold">{{ $selected->name }}</h2>
                        <button type="button" wire:click="resetSelection" class="{{ $link }}">Schließen</button>
                    </div>
                    @if($selected->trashed())
                        @if($view === 'trash')
                            <div class="flex flex-wrap gap-3">
                                <button type="button" wire:click="restore" wire:loading.attr="disabled" class="{{ $button }}">Wiederherstellen</button>
                                @if($purgeAfter && ! $purgeAfter->isFuture())
                                    <button type="button" wire:click="purge" wire:confirm="Diesen Eintrag und alle enthaltenen Dateien und Versionen endgültig löschen? Dies kann nicht rückgängig gemacht werden." wire:loading.attr="disabled" class="rounded-lg bg-red-700 px-3 py-2 text-sm font-semibold text-white">Endgültig löschen</button>
                                @else
                                    <p class="text-sm text-gray-500">Endgültiges Löschen möglich ab {{ $purgeAfter?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}.</p>
                                @endif
                            </div>
                        @endif
                    @else
                        <div class="flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="toggleFavorite" class="{{ $link }}">{{ $isFavorite ? '★ Favorit' : '☆ Als Favorit merken' }}</button>
                            <button type="button" wire:click="toggleSubscription" class="{{ $link }}">{{ $isSubscribed ? 'Abo beenden' : 'Bei Änderungen benachrichtigen' }}</button>
                            @if($selected->kind === 'folder')
                                <a href="{{ route('dateien.zip', ['node' => $selected->id]) }}" class="{{ $link }}">Als ZIP herunterladen</a>
                            @endif
                        </div>
                        @if($hasPreview)
                            <img src="{{ route('dateien.preview', ['node' => $selected->id, 'v' => $versions->first()->id]) }}" alt="Vorschau von {{ $selected->name }}" loading="lazy" class="max-h-80 max-w-full rounded-lg border border-gray-100">
                        @endif
                        @if($access->can($user, $selected, 'edit'))
                            <form wire:submit="saveDescription" class="space-y-1">
                                <label class="block text-sm">Beschreibung
                                    <textarea wire:model="description" maxlength="2000" rows="2" class="{{ $input }} mt-1 block w-full"></textarea>
                                </label>
                                <button class="{{ $button }}" wire:loading.attr="disabled">Beschreibung speichern</button>
                            </form>
                        @elseif($selected->description)
                            <p class="whitespace-pre-line text-sm text-gray-700">{{ $selected->description }}</p>
                        @endif
                        @if($access->can($user, $selected, 'edit'))
                            <form wire:submit="rename" class="flex flex-wrap items-end gap-2">
                                <label class="text-sm">Name<input wire:model="name" required maxlength="255" class="{{ $input }} ml-2"></label>
                                <button class="{{ $button }}" wire:loading.attr="disabled">Umbenennen</button>
                            </form>
                        @endif
                        @if($access->can($user, $selected, 'manage'))
                            <form wire:submit="move" class="flex flex-wrap items-end gap-2">
                                <label class="text-sm">Zielordner
                                    <select wire:model="destination" class="{{ $input }} ml-2">
                                        @php
                                            $selectedSpace = $currentSpace ?? \Extensions\Dateien\Models\Space::query()->find($selected->space_id);
                                        @endphp
                                        @if($selectedSpace && $access->can($user, $selectedSpace, 'manage'))
                                            <option value="">Bereichs-Stammordner</option>
                                        @else
                                            <option value="" disabled>Ziel auswählen</option>
                                        @endif
                                        @foreach($destinations as $dest)<option value="{{ $dest->id }}">{{ $dest->name }} (#{{ $dest->id }})</option>@endforeach
                                    </select>
                                </label>
                                <button class="{{ $button }}" wire:loading.attr="disabled">Verschieben</button>
                            </form>
                            <button type="button" wire:click="trash" wire:confirm="Diesen Eintrag einschließlich Unterordnern in den Papierkorb verschieben?" wire:loading.attr="disabled" class="text-sm font-medium text-red-700 underline">In den Papierkorb</button>
                        @endif
                        @if($selected->kind === 'file')
                            @if($officeEditUrl)
                                <a href="{{ $officeEditUrl }}" target="_blank" rel="noopener" class="{{ $button }}">Im Office bearbeiten</a>
                            @endif
                            @if($officeUrl)
                                <a href="{{ $officeUrl }}" target="_blank" rel="noopener" class="{{ $button }}">Im Office ansehen</a>
                            @endif
                            @if($officeEditing)
                                <p class="text-sm text-gray-600" role="status">Wird gerade im Online-Office bearbeitet. Neue Versionen können erst danach hochgeladen werden.</p>
                            @endif
                            <h3 class="font-semibold">Versionen</h3>
                            @if($access->can($user, $selected, 'edit'))
                                @include('dateien::partials.upload', ['label' => 'Neue Version', 'multiple' => false, 'parent' => null, 'spaceId' => null, 'nodeId' => $selected->id])
                            @endif
                            <ul class="divide-y divide-gray-100">
                                @forelse($versions as $revision)
                                    <li class="flex flex-wrap items-center gap-3 py-2 text-sm" wire:key="version-{{ $revision->id }}">
                                        <span>Version {{ $revision->number }} · {{ number_format($revision->size / 1024, 1, ',', '.') }} KB · {{ $revision->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</span>
                                        <a href="{{ route('dateien.download', ['node' => $selected->id, 'version' => $revision->id]) }}" class="{{ $link }}">Herunterladen</a>
                                        @if($access->can($user, $selected, 'edit'))
                                            <button type="button" wire:click="restoreVersion({{ $revision->id }})" wire:confirm="Diese Version als neue aktuelle Version wiederherstellen?" wire:loading.attr="disabled" class="{{ $link }}">Wiederherstellen</button>
                                        @endif
                                    </li>
                                @empty
                                    <li class="text-sm text-gray-500">Keine Versionen.</li>
                                @endforelse
                            </ul>
                        @endif
                        <h3 class="font-semibold">Kommentare</h3>
                        <ul class="space-y-2">
                            @forelse($comments as $comment)
                                <li class="text-sm" wire:key="comment-{{ $comment->id }}">
                                    <p class="text-xs text-gray-500">{{ $comment->author?->name ?? 'Unbekannt' }} · {{ $comment->created_at?->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
                                        @if($comment->user_id === $user->id || $access->can($user, $selected, 'manage'))
                                            <button type="button" wire:click="deleteComment({{ $comment->id }})" wire:confirm="Diesen Kommentar löschen?" class="{{ $link }} ml-2">Löschen</button>
                                        @endif
                                    </p>
                                    <p class="whitespace-pre-line break-words">{{ $comment->body }}</p>
                                </li>
                            @empty
                                <li class="text-sm text-gray-500">Noch keine Kommentare.</li>
                            @endforelse
                        </ul>
                        <form wire:submit="addComment" class="space-y-1">
                            <label class="block text-sm"><span class="sr-only">Kommentar</span>
                                <textarea wire:model="commentBody" required maxlength="2000" rows="2" placeholder="Kommentar schreiben …" class="{{ $input }} block w-full"></textarea>
                            </label>
                            <button class="{{ $button }}" wire:loading.attr="disabled">Kommentieren</button>
                        </form>
                    @endif
                </section>
            </div>
            @endif

    @if($canWriteHere && ! $searching)
        <div x-show="modal === 'folder'" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" x-on:click.self="modal = null">
            <form wire:submit="createFolder" class="w-full max-w-md space-y-3 rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="font-semibold">Neuer Ordner</h2>
                <input wire:model="folderName" required maxlength="255" placeholder="Name des Ordners" aria-label="Name des Ordners" x-ref="folderName" x-effect="if (modal === 'folder') $nextTick(() => $refs.folderName.focus())" class="{{ $input }} w-full">
                <div class="flex justify-end gap-2"><button type="button" x-on:click="modal = null" class="rounded-lg px-3 py-2 text-sm text-gray-600">Abbrechen</button><button class="{{ $button }}" wire:loading.attr="disabled">Erstellen</button></div>
            </form>
        </div>
        @if($newFileTypes !== [])
        <div x-show="modal === 'file'" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" x-on:click.self="modal = null">
            <form wire:submit="createFile" class="w-full max-w-md space-y-3 rounded-2xl bg-white p-5 shadow-xl">
                <h2 class="font-semibold">Neue Datei</h2>
                <div class="grid grid-cols-3 gap-2" role="radiogroup" aria-label="Dateityp">
                    @foreach($newFileTypes as $extension => $label)
                        <label class="flex cursor-pointer flex-col items-center gap-1 rounded-lg border p-2 text-center text-xs {{ $fileType === $extension ? 'border-fw-navy bg-blue-50 font-semibold text-fw-navy' : 'border-gray-200 text-gray-700 hover:bg-gray-50' }}">
                            <input type="radio" wire:model.live="fileType" value="{{ $extension }}" class="sr-only">
                            @include('dateien::partials.icon', ['kind' => 'file', 'ext' => $extension, 'class' => 'h-8 w-8'])
                            {{ $label }} <span class="text-gray-400">.{{ $extension }}</span>
                        </label>
                    @endforeach
                </div>
                <input wire:model="fileName" required maxlength="200" placeholder="Dateiname" aria-label="Dateiname" class="{{ $input }} w-full">
                <div class="flex justify-end gap-2"><button type="button" x-on:click="modal = null" class="rounded-lg px-3 py-2 text-sm text-gray-600">Abbrechen</button><button class="{{ $button }}" wire:loading.attr="disabled">Erstellen</button></div>
            </form>
        </div>
        @endif
        <div x-show="modal === 'upload'" x-cloak class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4" x-on:click.self="modal = null">
            <div class="w-full max-w-lg space-y-3 rounded-2xl bg-white p-5 shadow-xl">
                <div class="flex items-center justify-between"><h2 class="font-semibold">Dateien hochladen</h2><button type="button" x-on:click="modal = null" class="{{ $link }}">Schließen</button></div>
                @include('dateien::partials.upload', ['label' => 'Dateien oder Ordner auswählen', 'multiple' => true, 'parent' => $folder?->id, 'spaceId' => $currentSpace?->id, 'nodeId' => null])
            </div>
        </div>
    @endif

    <div x-show="menu.open" x-cloak x-transition.opacity.duration.100ms role="menu" class="fixed z-50 w-56 rounded-xl border border-gray-200 bg-white py-1 text-sm shadow-xl" x-bind:style="`left: ${menu.x}px; top: ${menu.y}px`" x-on:click.stop="menu.open = false" x-on:contextmenu.prevent>
        @php $mi = 'block w-full px-3 py-1.5 text-left text-gray-800 hover:bg-gray-50'; @endphp
        <template x-if="menu.node">
            <div>
                <button type="button" role="menuitem" class="{{ $mi }} font-semibold" x-on:click="menu.node.act === 'folder' ? $wire.navigate(null, menu.node.id) : (menu.node.act === 'result' ? $wire.openResult(menu.node.id) : $wire.select(menu.node.id))">Öffnen</button>
                <button type="button" role="menuitem" class="{{ $mi }}" x-on:click="$wire.select(menu.node.id)">Details und Umbenennen</button>
                <a x-show="menu.node.dl" x-bind:href="menu.node.dl" role="menuitem" class="{{ $mi }}" x-text="menu.node.folder ? 'Als ZIP herunterladen' : 'Herunterladen'"></a>
                <button type="button" role="menuitem" class="{{ $mi }}" x-show="menu.node.manage" x-on:click="$wire.select(menu.node.id, false).then(() => $dispatch('open-perms'))">Berechtigungen …</button>
                <button type="button" role="menuitem" class="{{ $mi }}" x-show="menu.node.trashed" x-on:click="$wire.select(menu.node.id, false).then(() => $wire.restore())">Wiederherstellen</button>
                <button type="button" role="menuitem" class="{{ $mi }} text-red-700" x-show="menu.node.manage" x-on:click="$wire.select(menu.node.id, false).then(() => { if (confirm('Diesen Eintrag einschließlich Unterordnern in den Papierkorb verschieben?')) { $wire.trash(); } else { $wire.resetSelection(); } })">In den Papierkorb</button>
            </div>
        </template>
        <template x-if="! menu.node">
            <div>
                @if($canWriteHere && ! $searching)
                    <button type="button" role="menuitem" class="{{ $mi }}" x-on:click="modal = 'folder'">Neuer Ordner</button>
                    @foreach($newFileTypes as $extension => $label)
                        <button type="button" role="menuitem" class="{{ $mi }}" x-on:click="$wire.set('fileType', '{{ $extension }}'); modal = 'file'"><span class="flex items-center gap-2">@include('dateien::partials.icon', ['kind' => 'file', 'ext' => $extension, 'class' => 'h-4 w-4'])Neu: {{ $label }} (.{{ $extension }})</span></button>
                    @endforeach
                    <button type="button" role="menuitem" class="{{ $mi }}" x-on:click="modal = 'upload'">Dateien hochladen …</button>
                @endif
                @if($canManage && $view !== 'trash')
                    <button type="button" role="menuitem" class="{{ $mi }}" x-on:click="$wire.resetSelection().then(() => $dispatch('open-perms'))">Berechtigungen dieses Ordners …</button>
                @endif
                @if(! ($canWriteHere && ! $searching) && ! ($canManage && $view !== 'trash'))
                    <p class="px-3 py-1.5 text-gray-500">Keine Aktionen verfügbar.</p>
                @endif
            </div>
        </template>
    </div>
    <p wire:loading role="status" class="text-sm text-gray-500">Wird verarbeitet …</p>
</div>
