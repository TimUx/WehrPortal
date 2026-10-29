@php
    $input = 'rounded-lg border border-gray-300 px-3 py-2 text-base sm:text-sm focus:border-fw-red focus:ring-fw-red';
    $button = 'inline-flex items-center rounded-lg bg-fw-navy px-3 py-2 text-sm font-semibold text-white disabled:opacity-50';
    $link = 'text-sm font-medium text-fw-navy underline';
@endphp
<div class="mx-auto max-w-6xl space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold text-gray-900">Dateien</h1>
        <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto">
            <label class="min-w-48 flex-1 text-sm sm:w-72 sm:flex-none"><span class="sr-only">Dateien suchen</span>
                <input type="search" wire:model.live.debounce.400ms="search" maxlength="100" placeholder="Name suchen …" class="{{ $input }} w-full">
            </label>
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1" role="group" aria-label="Ansicht wählen">
                <button type="button" wire:click="setLayout('list')" aria-pressed="{{ $layout === 'list' ? 'true' : 'false' }}" class="rounded-md px-2 py-1 text-sm {{ $layout === 'list' ? 'bg-fw-navy text-white' : 'text-fw-navy' }}">Liste</button>
                <button type="button" wire:click="setLayout('table')" aria-pressed="{{ $layout === 'table' ? 'true' : 'false' }}" class="rounded-md px-2 py-1 text-sm {{ $layout === 'table' ? 'bg-fw-navy text-white' : 'text-fw-navy' }}">Tabelle</button>
                <button type="button" wire:click="setLayout('tiles')" aria-pressed="{{ $layout === 'tiles' ? 'true' : 'false' }}" class="rounded-md px-2 py-1 text-sm {{ $layout === 'tiles' ? 'bg-fw-navy text-white' : 'text-fw-navy' }}">Kacheln</button>
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
    @php
        $hasDetails = $selected || ($canManage && $view !== 'trash') || ($shareNode && ($canShare || $shares->isNotEmpty()));
    @endphp
    <div class="flex flex-col gap-4 md:flex-row md:items-start">
        <aside class="w-full shrink-0 space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm md:w-56">
            <h2 class="font-semibold">Bereiche</h2>
            @forelse($spaces as $item)
                <button type="button" wire:click="navigate({{ $item->id }})" class="block w-full break-words text-left text-sm text-fw-navy">{{ $item->name }}</button>
            @empty
                <p class="text-sm text-gray-500">Keine freigegebenen Bereiche.</p>
            @endforelse
            @if($user->is_admin)
                <form wire:submit="createSpace" class="space-y-2 border-t border-gray-100 pt-3">
                    <label class="block text-sm font-medium">Gemeinsame Ablage
                        <input wire:model="spaceName" required maxlength="255" placeholder="Name der Ablage" class="{{ $input }} mt-1 w-full">
                    </label>
                    <button class="{{ $button }} w-full justify-center" wire:loading.attr="disabled">Ablage erstellen</button>
                </form>
            @endif
            <hr class="border-gray-100">
            <button type="button" wire:click="navigate(null, null, 'favorites')" class="block text-sm text-fw-navy">Favoriten</button>
            <button type="button" wire:click="navigate(null, null, 'recent')" class="block text-sm text-fw-navy">Zuletzt verwendet</button>
            <button type="button" wire:click="navigate(null, null, 'shared')" class="block text-sm text-fw-navy">Mit mir geteilt</button>
            <button type="button" wire:click="navigate(null, null, 'trash')" class="block text-sm text-fw-navy">Papierkorb</button>
        </aside>
        <main class="min-w-0 space-y-4">
            <div class="flex flex-wrap items-center gap-2 text-sm" aria-label="Ordnerpfad">
                @if($currentSpace && $access->can($user, $currentSpace, 'read'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }})" class="{{ $link }}">{{ $currentSpace->name }}</button>
                @elseif($view === 'shared' || $folder)
                    <button type="button" wire:click="navigate(null, null, 'shared')" class="{{ $link }}">Mit mir geteilt</button>
                @elseif($view !== 'trash')
                    <span class="text-gray-500">Bitte einen Bereich auswählen.</span>
                @endif
                @foreach($breadcrumbs as $crumb)
                    <span aria-hidden="true">/</span>
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
                <div class="ml-auto flex items-center gap-3">
                    @if($currentSpace && $view !== 'trash' && $access->can($user, $currentSpace, 'manage'))
                        <button type="button" wire:click="navigate({{ $currentSpace->id }}, null, 'trash')" class="{{ $link }}">Papierkorb dieses Bereichs</button>
                    @endif
                    @if($canWriteHere && ! $searching)
                        <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                            <button type="button" x-on:click="open = !open" aria-haspopup="true" x-bind:aria-expanded="open" class="{{ $button }}">+ Neuer Ordner</button>
                            <div x-show="open" x-cloak class="absolute right-0 z-20 mt-2 w-80 max-w-[90vw] space-y-3 rounded-xl border border-gray-200 bg-white p-3 shadow-lg">
                                <form wire:submit="createFolder" class="flex flex-wrap items-end gap-2">
                                    <label class="min-w-0 flex-1 text-sm">Neuer Ordner
                                        <input wire:model="folderName" required maxlength="255" class="{{ $input }} mt-1 w-full">
                                    </label>
                                    <button class="{{ $button }}" wire:loading.attr="disabled">Erstellen</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            @if($quotaWarning)
                <p role="alert" class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">Speicherplatz dieses Bereichs zu {{ $quotaWarning['percent'] }} % belegt ({{ number_format($quotaWarning['used'] / 1048576, 1, ',', '.') }} von {{ number_format($quotaWarning['quota'] / 1048576, 1, ',', '.') }} MB, inklusive Versionen und Papierkorb).</p>
            @endif
            @if($searching)
                <p class="text-sm text-gray-600">Suchergebnisse für „{{ $search }}“ (nur Einträge, die Sie öffnen dürfen; höchstens 50).</p>
            @endif
            @if($effectiveLevel && ! $searching)
                <p class="text-sm text-gray-600">Ihr Recht: {{ ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'][$effectiveLevel] }}</p>
            @endif
            @if($view === 'trash')
                <p class="text-sm text-gray-500">Aufbewahrungsfrist im Papierkorb: {{ $retentionDays }} Tage. Danach können Einträge endgültig gelöscht werden. Das kann nicht rückgängig gemacht werden.</p>
            @endif
            <div class="flex flex-col gap-3 lg:flex-row lg:items-start">
            @if($currentSpace && $folderTree !== [] && ! $searching)
                <nav aria-label="Ordnerstruktur" class="max-h-[32rem] w-full shrink-0 space-y-0.5 overflow-y-auto rounded-2xl border border-gray-100 bg-white p-2 shadow-sm lg:w-56">
                    @if($access->can($user, $currentSpace, 'read'))
                    <button type="button" wire:click="navigate({{ $currentSpace->id }})" class="block w-full truncate rounded px-2 py-1 text-left text-sm {{ ! $folder ? 'bg-blue-50 font-semibold text-fw-navy' : 'text-gray-700 hover:bg-gray-50' }}">
                        <span aria-hidden="true">🗂️</span> {{ $currentSpace->name }}
                    </button>
                    @endif
                    @foreach($folderTree as $treeRow)
                        <button type="button" wire:click="navigate(null, {{ $treeRow['node']->id }})" style="padding-left: {{ min($treeRow['depth'] * 1.25 + 0.5, 8) }}rem" class="block w-full truncate rounded px-2 py-1 text-left text-sm {{ $folder?->id === $treeRow['node']->id ? 'bg-blue-50 font-semibold text-fw-navy' : 'text-gray-700 hover:bg-gray-50' }}">
                            <span aria-hidden="true">📁</span> {{ $treeRow['node']->name }}
                        </button>
                    @endforeach
                </nav>
            @endif
            <div class="min-w-0 flex-1 space-y-3">
            @if($canWriteHere && ! $searching)
                @include('dateien::partials.upload', ['label' => 'Dateien hochladen', 'multiple' => true, 'parent' => $folder?->id, 'spaceId' => $currentSpace?->id, 'nodeId' => null])
            @endif
            @if($layout === 'table')
                <div class="overflow-x-auto rounded-2xl border border-gray-100 bg-white shadow-sm">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr><th class="px-4 py-3">Name</th><th class="px-4 py-3">Typ</th><th class="px-4 py-3">Aktion</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($rows as $row)
                                <tr wire:key="node-{{ $row->id }}">
                                    <td class="max-w-0 truncate px-4 py-3 font-medium text-fw-navy">
                                        @if($searching)<button type="button" wire:click="openResult({{ $row->id }})">{{ $row->name }}</button>
                                        @elseif($row->kind === 'folder' && $view !== 'trash')<button type="button" wire:click="navigate(null, {{ $row->id }})">{{ $row->name }}</button>
                                        @else<button type="button" wire:click="select({{ $row->id }})">{{ $row->name }}</button>@endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-gray-500">{{ $row->kind === 'folder' ? 'Ordner' : 'Datei' }}@if($row->trashed()) · Gelöscht {{ $row->deleted_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}@endif</td>
                                    <td class="whitespace-nowrap px-4 py-3"><button type="button" wire:click="{{ $searching ? 'openResult' : 'select' }}({{ $row->id }})" class="{{ $link }}">{{ $searching ? 'Öffnen' : 'Details' }}</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-4 py-6 text-sm text-gray-500">{{ $searching ? 'Keine Treffer.' : 'Keine Einträge.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @else
                <div class="{{ $layout === 'tiles' ? 'grid gap-3 sm:grid-cols-2 lg:grid-cols-3' : 'divide-y divide-gray-100' }} rounded-2xl border border-gray-100 bg-white shadow-sm">
                    @forelse($rows as $row)
                        <div wire:key="node-{{ $row->id }}" class="{{ $layout === 'tiles' ? 'min-w-0 rounded-xl p-4' : 'flex items-center justify-between gap-3 px-4 py-3' }}">
                            <div class="min-w-0">
                                @if($searching)
                                    <button type="button" wire:click="openResult({{ $row->id }})" class="break-words text-left font-semibold text-fw-navy">{{ $row->name }}</button>
                                @elseif($row->kind === 'folder' && $view !== 'trash')
                                    <button type="button" wire:click="navigate(null, {{ $row->id }})" class="break-words text-left font-semibold text-fw-navy">{{ $layout === 'tiles' ? '📁 ' : '' }}{{ $row->name }}</button>
                                @else
                                    <button type="button" wire:click="select({{ $row->id }})" class="break-words text-left font-semibold text-gray-900">{{ $layout === 'tiles' ? '📄 ' : '' }}{{ $row->name }}</button>
                                @endif
                                <p class="text-xs text-gray-500">{{ $row->kind === 'folder' ? 'Ordner' : 'Datei' }}@if($row->trashed()) · Gelöscht {{ $row->deleted_at->timezone('Europe/Berlin')->format('d.m.Y H:i') }}@endif</p>
                            </div>
                            <button type="button" wire:click="{{ $searching ? 'openResult' : 'select' }}({{ $row->id }})" class="{{ $link }}">{{ $searching ? 'Öffnen' : 'Details' }}</button>
                        </div>
                    @empty
                        <p class="px-4 py-6 text-sm text-gray-500">{{ $searching ? 'Keine Treffer.' : 'Keine Einträge.' }}</p>
                    @endforelse
                </div>
            @endif
            </div>
            </div>
        </main>
        @if($hasDetails)
        <aside aria-label="Details" class="w-full min-w-0 shrink-0 space-y-4 md:w-80">
            @if($selected)
                <section class="space-y-4 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
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
            @endif
            @if($canManage && $view !== 'trash')
                <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h2 class="font-semibold">Berechtigungen: {{ $target->name }}</h2>
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
                </section>
            @endif
            @if($shareNode && ($canShare || $shares->isNotEmpty()))
                <section class="space-y-3 rounded-2xl border border-gray-100 bg-white p-4 shadow-sm">
                    <h2 class="font-semibold">Öffentliche Links: {{ $shareNode->name }}</h2>
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
                </section>
            @endif
        </aside>
        @endif
    </div>
    <p wire:loading role="status" class="text-sm text-gray-500">Wird verarbeitet …</p>
</div>
