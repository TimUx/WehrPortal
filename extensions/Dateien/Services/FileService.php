<?php

namespace Extensions\Dateien\Services;

use App\Models\Setting;
use App\Models\User;
use App\Support\FileResponse;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\FileGroup;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\OfficeSession;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Dateien\Models\Version;
use Extensions\Dateien\Notifications\SharedWithYouNotification;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class FileService
{
    public function __construct(private FileAccess $access) {}

    public static function maxUploadLimitKb(): int
    {
        return max(1, (int) config('dateien.max_upload_limit_kb', 51200));
    }

    public static function maxUploadKb(): int
    {
        return max(1, min(self::maxUploadLimitKb(), (int) Setting::get('dateien.max_upload_kb', config('dateien.max_upload_kb', 20480))));
    }

    public static function allowedExtensions(): array
    {
        $extensions = Setting::get('dateien.extensions', config('dateien.extensions', FileResponse::allowedExtensions()));
        if (is_string($extensions)) {
            $extensions = json_decode($extensions, true);
        }

        return array_values(array_intersect(FileResponse::allowedExtensions(), is_array($extensions) ? $extensions : []));
    }

    public static function retentionDays(): int
    {
        return max(0, min(3650, (int) Setting::get('dateien.retention_days', config('dateien.retention_days', 30))));
    }

    public static function quotaWarningPercent(): int
    {
        return max(1, min(100, (int) Setting::get('dateien.quota_warning_percent', config('dateien.quota_warning_percent', 90))));
    }

    public static function auditRetentionDays(): int
    {
        return max(0, min(36500, (int) Setting::get('dateien.audit_retention_days', config('dateien.audit_retention_days', 730))));
    }

    public static function verifyDownloads(): bool
    {
        return filter_var(Setting::get('dateien.verify_downloads', config('dateien.verify_downloads', true)), FILTER_VALIDATE_BOOLEAN);
    }

    /** Bytes counted against the quota: all versions, including the trash */
    public function usage(Space $space): int
    {
        return (int) Version::whereIn('node_id', Node::withTrashed()->where('space_id', $space->id)->select('id'))->sum('size');
    }

    /** @return array{used: int, quota: int, percent: int}|null when the space has a quota and its warning threshold is reached */
    public function quotaWarning(Space $space): ?array
    {
        if ($space->quota_bytes === null || $space->quota_bytes <= 0) {
            return null;
        }
        $used = $this->usage($space);
        $percent = (int) floor($used * 100 / $space->quota_bytes);

        return $percent >= self::quotaWarningPercent() ? ['used' => $used, 'quota' => $space->quota_bytes, 'percent' => $percent] : null;
    }

    public function createSpace(User $user, array $data): Space
    {
        $user = $this->access->member($user);
        if (! $user->is_admin) {
            throw new AuthorizationException;
        }
        $name = $this->name((string) ($data['name'] ?? ''));
        $quota = $data['quota_bytes'] ?? null;
        if ($quota !== null && (filter_var($quota, FILTER_VALIDATE_INT) === false || $quota < 0)) {
            $this->invalid('quota_bytes', 'Das Speicherlimit muss eine nichtnegative ganze Zahl sein.');
        }
        $location = $data['ext_location_id'] ?? null;
        if ($location !== null && (! config('locations.enabled') || filter_var($location, FILTER_VALIDATE_INT) === false
            || ! DB::table('x_locations')->where('id', $location)->exists())) {
            $this->invalid('ext_location_id', 'Bitte einen gültigen Standort auswählen.');
        }

        return DB::transaction(function () use ($user, $data, $name, $quota) {
            $this->access->member($user);
            $space = Space::create([
                'name' => $name,
                'ext_location_id' => $data['ext_location_id'] ?? null,
                'quota_bytes' => $quota,
            ]);
            $this->access->authorize($user, $space, 'manage');
            $this->audit($user, 'space.created', $space);

            return $space;
        });
    }

    public function createFolder(User $user, Space $space, ?Node $parent, string $name): Node
    {
        return $this->mutate($user, $space, function (Space $space) use ($user, $parent, $name) {
            $parent = $this->destination($user, $space, $parent, 'edit');
            $node = Node::create(['space_id' => $space->id, 'parent_id' => $parent?->id, 'name' => $this->name($name), 'kind' => 'folder']);
            $this->audit($user, 'folder.created', $space, $node);
            $this->collaboration()->notifySubscribers($user, $node, 'created');

            return $node;
        });
    }

    public function upload(User $user, Space $space, ?Node $parent, UploadedFile $file, string $relativePath = ''): Node
    {
        $keys = [];
        try {
            return $this->mutate($user, $space, function (Space $space) use ($user, $parent, $file, $relativePath, &$keys) {
                $parent = $this->ensureFolders($user, $space, $parent, $relativePath);
                $name = $this->name($file->getClientOriginalName());
                $node = Node::create(['space_id' => $space->id, 'parent_id' => $parent?->id, 'name' => $name, 'kind' => 'file']);
                $this->storeVersion($user, $space, $node, $file, null, $keys);
                $this->audit($user, 'file.created', $space, $node);
                $this->collaboration()->notifySubscribers($user, $node, 'created');

                return $node;
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    /** @param  list<string>  $paths */
    public function createFolderPaths(User $user, Space $space, ?Node $parent, array $paths): int
    {
        return $this->mutate($user, $space, function (Space $space) use ($user, $parent, $paths) {
            $created = 0;
            foreach ($paths as $path) {
                $this->ensureFolders($user, $space, $parent, $path, $created);
            }

            return $created;
        });
    }

    public function addVersion(User $user, Node $node, UploadedFile $file, ?int $expectedVersion = null): Version
    {
        $keys = [];
        try {
            return $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $file, $expectedVersion, &$keys) {
                return $this->storeVersion($user, $space, $node, $file, $expectedVersion, $keys);
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    /**
     * Stores a file returned by the office server as a new version (source "office"). The Kamerad of
     * the session needs edit rights now; the quota applies. An autosave of the same session that is
     * still the newest version is replaced, so one session leaves one version plus explicit saves.
     */
    public function addOfficeVersion(User $user, Node $node, UploadedFile $file, int $sessionId, bool $autosave): Version
    {
        $keys = [];
        try {
            return $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $file, $sessionId, $autosave, &$keys) {
                return $this->storeVersion($user, $space, $node, $file, null, $keys, ['session' => $sessionId, 'autosave' => $autosave]);
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    public function restoreVersion(User $user, Node $node, Version $version, ?int $expectedVersion = null): Version
    {
        $keys = [];
        try {
            return $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $version, $expectedVersion, &$keys) {
                $this->assertFile($node);
                $this->assertNotEditedInOffice($node);
                $version = Version::where('node_id', $node->id)->find($version->id);
                if (! $version) {
                    $this->invalid('version', 'Diese Version gehört nicht zu dieser Datei.');
                }
                $latest = $this->latest($node, $expectedVersion);
                if ($latest && hash_equals($latest->checksum, $version->checksum)) {
                    return $latest;
                }
                $this->quota($space, $version->size);
                $key = $this->key($space);
                $keys[] = $key;
                $stream = $this->disk()->readStream($this->storageKey($version->storage_key));
                if (! is_resource($stream)) {
                    $this->invalid('file', 'Die gespeicherte Datei ist nicht verfügbar.');
                }
                try {
                    $this->write($key, $stream);
                } finally {
                    fclose($stream);
                }
                if (! hash_equals($version->checksum, hash_file('sha256', $this->disk()->path($key)))) {
                    $this->invalid('file', 'Die Prüfsumme der gespeicherten Datei stimmt nicht.');
                }
                $new = Version::create([
                    'node_id' => $node->id, 'number' => ($latest->number ?? 0) + 1, 'storage_key' => $key,
                    'checksum' => $version->checksum, 'size' => $version->size, 'mime' => $version->mime,
                    'extension' => $version->extension, 'created_by' => $user->id, 'source' => 'restore',
                ]);
                $this->audit($user, 'version.restored', $space, $node, ['version_id' => $new->id, 'source_version_id' => $version->id]);

                return $new;
            });
        } catch (Throwable $e) {
            $this->cleanup($keys);
            throw $e;
        }
    }

    public function rename(User $user, Node $node, string $name): void
    {
        $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $name) {
            $name = $this->name($name);
            if ($node->kind === 'file' && strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== strtolower(pathinfo($node->name, PATHINFO_EXTENSION))) {
                $this->invalid('name', 'Die Dateiendung darf nicht geändert werden.');
            }
            $node->update(['name' => $name]);
            $this->audit($user, 'node.renamed', $space, $node);
        });
    }

    /** A short text about the entry; edit right, searchable through the full-text index */
    public function describe(User $user, Node $node, ?string $description): void
    {
        $description = $description === null ? null : trim($description);
        if ($description !== null && (mb_strlen($description) > 2000 || ! mb_check_encoding($description, 'UTF-8'))) {
            $this->invalid('description', 'Die Beschreibung darf höchstens 2000 Zeichen lang sein.');
        }
        $this->mutateNode($user, $node, 'edit', function (Space $space, Node $node) use ($user, $description) {
            $node->forceFill(['description' => $description === '' ? null : $description])->save();
            $this->audit($user, 'node.described', $space, $node);
        });
    }

    public function move(User $user, Node $node, ?Node $parent): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user, $parent) {
            $parent = $this->destination($user, $space, $parent, 'manage');
            $ancestor = $parent;
            while ($ancestor) {
                if ($ancestor->id === $node->id) {
                    $this->invalid('parent_id', 'Ein Ordner kann nicht in sich selbst verschoben werden.');
                }
                $ancestor = $ancestor->parent_id ? Node::withTrashed()->findOrFail($ancestor->parent_id) : null;
            }
            $old = $node->parent_id;
            $node->update(['parent_id' => $parent?->id]);
            $this->audit($user, 'node.moved', $space, $node, ['from_parent_id' => $old, 'to_parent_id' => $parent?->id]);
        });
    }

    public function trash(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            $node->delete();
            $this->audit($user, 'node.trashed', $space, $node);
        });
    }

    public function restore(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            if (! $node->trashed()) {
                $this->invalid('node', 'Dieser Eintrag liegt nicht im Papierkorb.');
            }
            $node->restore();
            $this->audit($user, 'node.restored', $space, $node);
        }, true);
    }

    public function purge(User $user, Node $node): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user) {
            if (! $node->trashed()) {
                $this->invalid('node', 'Nur Papierkorb-Einträge können endgültig gelöscht werden.');
            }
            if ($node->deleted_at->copy()->addDays(self::retentionDays())->isFuture()) {
                $this->invalid('node', 'Die Mindestaufbewahrungsfrist im Papierkorb ist noch nicht abgelaufen.');
            }
            $ids = $this->descendantIds($node);
            $keys = Version::whereIn('node_id', $ids)->pluck('storage_key');
            foreach ($keys as $key) {
                DB::table('x_files_pending_deletions')->insertOrIgnore(['storage_key' => $this->storageKey($key), 'created_at' => now()]);
            }
            $this->audit($user, 'node.purged', $space, $node, ['node_count' => count($ids), 'version_count' => $keys->count()]);
            $node->forceDelete();
            // A durable deletion outbox survives crashes and storage failures after the DB commit.
            DB::afterCommit(function () {
                try {
                    $this->drainDeletions();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }, true);
    }

    public function setPermission(User $user, Space|Node $target, string $subjectType, int $subjectId, ?string $level): void
    {
        $space = $target instanceof Space ? $target : $this->spaceOf($target);
        $this->mutate($user, $space, function (Space $space) use ($user, $target, $subjectType, $subjectId, $level) {
            $target = $target instanceof Node ? $this->freshNode($target, $space) : $space;
            $this->access->authorize($user, $target, 'manage');
            if ($subjectId < 1 || ! in_array($subjectType, ['user', 'group', 'department'], true) || ($level !== null && ! isset(FileAccess::LEVELS[$level]))) {
                $this->invalid('permission', 'Ungültige Berechtigung.');
            }
            if ($subjectType === 'user' && $level !== null) {
                $subject = User::withoutGlobalScopes()->find($subjectId);
                if (! $subject) {
                    throw new AuthorizationException('Keine Freigabe für diesen Benutzer möglich.');
                }
                $subject = $this->access->member($subject);
                if (config('locations.enabled') && ! $subject->is_admin && $space->ext_location_id !== null
                    && ! (new LocationAccess)->canSee($subject, (int) $space->ext_location_id)) {
                    throw new AuthorizationException('Keine Freigabe für diesen Benutzer möglich.');
                }
            } elseif ($subjectType === 'group' && $level !== null) {
                $actor = $this->access->member($user);
                $query = FileGroup::whereKey($subjectId);
                if (config('locations.enabled') && ! $actor->is_admin) {
                    $locations = (new LocationAccess)->visibleLocationIds($actor);
                    $query->where(fn ($q) => $q->whereNull('ext_location_id')->orWhereIn('ext_location_id', $locations));
                }
                $group = $query->first();
                if (! $group || (config('locations.enabled') && $space->ext_location_id !== null
                    && $group->ext_location_id !== null && $group->ext_location_id !== $space->ext_location_id)) {
                    throw new AuthorizationException('Kein Zugriff auf diese Dateigruppe.');
                }
            } elseif ($subjectType === 'department' && $level !== null) {
                $department = DB::table('groups')->where('id', $subjectId)->first(['id', 'ext_location_id']);
                if (! $department || (config('locations.enabled') && $space->ext_location_id !== null
                    && (int) $department->ext_location_id !== (int) $space->ext_location_id)) {
                    throw new AuthorizationException('Keine Freigabe für diese Abteilung möglich.');
                }
            }
            $key = ['target_type' => $target instanceof Space ? 'space' : 'node', 'target_id' => $target->id, 'subject_type' => $subjectType, 'subject_id' => $subjectId];
            $previous = Permission::where($key)->value('level');
            if ($level === null) {
                Permission::where($key)->delete();
            } else {
                Permission::updateOrCreate($key, ['level' => $level]);
                if ($target instanceof Node && $target->kind === 'folder' && $target->inherit_permissions) {
                    $target->update(['inherit_permissions' => false]);
                }
                if ($previous !== $level) {
                    DB::afterCommit(fn () => $this->notifyShared($user, $target, $subjectType, $subjectId, $level));
                }
            }
            $this->audit($user, 'permission.changed', $space, $target instanceof Node ? $target : null, ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'level' => $level]);
        });
    }

    public function setInheritance(User $user, Node $node, bool $inherit): void
    {
        $this->mutateNode($user, $node, 'manage', function (Space $space, Node $node) use ($user, $inherit) {
            $node->update(['inherit_permissions' => $inherit]);
            $this->audit($user, 'inheritance.changed', $space, $node, ['inherit_permissions' => $inherit]);
        });
    }

    /** @return Collection<int, Node> */
    public function children(User $user, Space $space, ?Node $parent): Collection
    {
        $this->access->member($user);
        $space = Space::findOrFail($space->id);
        if ($parent) {
            $parent = $this->freshNode($parent, $space);
            if ($parent->kind !== 'folder') {
                $this->invalid('parent_id', 'Das Ziel muss ein Ordner sein.');
            }
        }
        $this->access->authorize($user, $parent ?? $space, 'read');

        return Node::where('space_id', $space->id)->where('parent_id', $parent?->id)->orderBy('kind')->orderBy('name')->get()
            ->filter(fn (Node $node) => $this->access->can($user, $node, 'read'))->values();
    }

    /** @return Collection<int, Space> */
    public function spaces(User $user): Collection
    {
        $this->access->member($user);

        return Space::orderBy('name')->get()->filter(fn (Space $space) => $this->access->can($user, $space, 'read'))->values();
    }

    /** @return Collection<int, Node> */
    public function shared(User $user): Collection
    {
        $user = $this->access->member($user);
        $groups = DB::table('x_files_group_members')->where('user_id', $user->id)->pluck('group_id');
        $ids = Permission::where('target_type', 'node')->where(function ($q) use ($user, $groups) {
            $q->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'group')->whereIn('subject_id', $groups));
        })->pluck('target_id');

        return Node::whereIn('id', $ids)->orderBy('name')->get()->filter(fn (Node $node) => $this->access->can($user, $node, 'read'))->values();
    }

    /**
     * Name and full-text search (name + description) over active entries. The database narrows the candidates to spaces the user can
     * reach at all (space permission, or any direct share inside); every hit is then checked
     * with the full ACL, so nothing from a locked area, another Standort or the trash leaks.
     *
     * @return Collection<int, Node>
     */
    public function search(User $user, string $term, ?Space $space = null, int $limit = 50): Collection
    {
        $user = $this->access->member($user);
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
        // Name substring, or the German full-text index over name and description (x_files_nodes_fulltext)
        $query = Node::query()->where(fn ($q) => $q->whereRaw("lower(name) LIKE ? ESCAPE '\\'", [$pattern])
            ->orWhereRaw("to_tsvector('german', name || ' ' || coalesce(description, '')) @@ plainto_tsquery('german', ?)", [$term]))
            ->orderBy('name')->orderBy('id');
        if ($space) {
            $query->where('space_id', $space->id);
        }
        if (! $user->is_admin) {
            $groups = DB::table('x_files_group_members')->where('user_id', $user->id)->select('group_id');
            $subject = fn ($q) => $q->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                ->orWhere(fn ($q) => $q->where('subject_type', 'group')->whereIn('subject_id', $groups));
            $spaces = Permission::where('target_type', 'space')->where($subject)->select('target_id');
            $shared = Node::withTrashed()->whereIn('id', Permission::where('target_type', 'node')->where($subject)->select('target_id'))->select('space_id');
            $query->where(fn ($q) => $q->whereIn('space_id', $spaces)->orWhereIn('space_id', $shared));
        }

        $hits = collect();
        foreach ($query->limit(1000)->get() as $node) {
            if ($this->access->can($user, $node, 'read')) {
                $hits->push($node);
                if ($hits->count() >= $limit) {
                    break;
                }
            }
        }

        return $hits;
    }

    /** @return Collection<int, Node> */
    public function trashItems(User $user, Space $space): Collection
    {
        $this->access->member($user);

        return Node::onlyTrashed()->where('space_id', $space->id)->orderByDesc('deleted_at')->get()->filter(function (Node $node) use ($user) {
            try {
                $this->access->authorizeTrashed($user, $node);

                return true;
            } catch (AuthorizationException) {
                return false;
            }
        })->values();
    }

    /** @return Collection<int, Version> */
    public function versions(User $user, Node $node): Collection
    {
        $this->access->authorize($user, $node, 'read');

        return Version::where('node_id', $node->id)->orderByDesc('number')->get();
    }

    /**
     * Streams a version from the private disk: constant memory, HTTP Range for PDF viewers and
     * media, the same fixed type/disposition/nosniff/sandbox rules as FileResponse. No lock on
     * the space – a long download must not block writers.
     */
    public function download(User $user, Node $node, ?Version $version = null, ?Request $request = null): SymfonyResponse
    {
        $this->access->authorize($user, $node, 'read');

        return $this->stream($node, $version, $request, function (Node $node, Version $version) use ($user) {
            $this->audit($user, 'version.downloaded', $this->spaceOf($node), $node, ['version_id' => $version->id]);
            $this->collaboration()->touchRecent($user, $node);
        });
    }

    /**
     * Streams a file without a Kamerad. Only for callers that authorized the access themselves
     * (public links in ShareService). $onComplete runs for full downloads (not for later ranges).
     *
     * @param  callable(Node, Version): void  $onComplete
     */
    public function stream(Node $node, ?Version $version, ?Request $request, callable $onComplete): SymfonyResponse
    {
        $node = Node::query()->findOrFail($node->id);
        $this->assertFile($node);
        $version = $version ? Version::where('node_id', $node->id)->findOrFail($version->id) : Version::where('node_id', $node->id)->orderByDesc('number')->firstOrFail();
        $key = $this->storageKey($version->storage_key);
        $disk = $this->disk();
        try {
            $exists = $disk->exists($key);
        } catch (FilesystemException $e) {
            report($e);
            $exists = false;
        }
        if (! $exists) {
            abort(404, 'Die gespeicherte Datei ist nicht verfügbar.');
        }
        $path = $disk->path($key);
        $range = ($request ?? request())->headers->get('Range');
        $partial = $range !== null && ! preg_match('/^bytes=0-/', $range);
        if ((! $partial && self::verifyDownloads()) || filesize($path) !== $version->size) {
            if (filesize($path) !== $version->size || ! hash_equals($version->checksum, (string) hash_file('sha256', $path))) {
                $this->invalid('file', 'Die Prüfsumme der gespeicherten Datei stimmt nicht.');
            }
        }
        if (! $partial) {
            $onComplete($node, $version);
        }

        $name = pathinfo($node->name, PATHINFO_FILENAME).'.'.$version->extension;
        $fallback = str_replace(['%', '/', '\\'], '_', preg_replace('/[^\x20-\x7e]/', '_', Str::ascii($name))) ?: 'datei.'.$version->extension;
        $type = FileResponse::INLINE[$version->extension] ?? FileResponse::DOWNLOAD[$version->extension] ?? 'application/octet-stream';
        $response = new BinaryFileResponse($path, 200, [], false, null, false, true);
        $response->headers->set('Content-Type', $type.(str_starts_with($type, 'text/') ? '; charset=utf-8' : ''));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            isset(FileResponse::INLINE[$version->extension]) ? 'inline' : 'attachment',
            $name,
            $fallback,
        ));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($type !== 'application/pdf') {
            $response->headers->set('Content-Security-Policy', "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
        }

        return $response;
    }

    public function purgeExpired(User $user): int
    {
        $user = $this->access->member($user);
        if (! $user->is_admin) {
            throw new AuthorizationException;
        }
        $days = self::retentionDays();
        $count = 0;
        foreach (Node::onlyTrashed()->where('deleted_at', '<=', now()->subDays($days))->get() as $node) {
            try {
                $this->access->authorizeTrashed($user, $node);
                $this->purge($user, $node);
                $count++;
            } catch (AuthorizationException) {
                // An already trashed ancestor owns the subtree and will purge it as one unit.
            }
        }
        $this->retryPendingDeletions($user);

        return $count;
    }

    public function retryPendingDeletions(User $user): void
    {
        if (! $this->access->member($user)->is_admin) {
            throw new AuthorizationException;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->drainDeletions());
        } else {
            $this->drainDeletions();
        }
    }

    /**
     * @template T
     *
     * @param  callable(Space): T  $callback
     * @return T
     */
    private function mutate(User $user, Space $space, callable $callback): mixed
    {
        try {
            return DB::transaction(function () use ($user, $space, $callback) {
                $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
                $this->access->member($user);

                return $callback($space);
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                $this->invalid('name', 'Ein Eintrag mit diesem Namen existiert bereits.');
            }
            throw $e;
        }
    }

    /**
     * @template T
     *
     * @param  callable(Space, Node): T  $callback
     * @return T
     */
    private function mutateNode(User $user, Node $node, string $level, callable $callback, bool $trashed = false): mixed
    {
        return $this->mutate($user, $this->spaceOf($node), function (Space $space) use ($user, $node, $level, $callback, $trashed) {
            $node = $this->freshNode($node, $space);
            if ($trashed) {
                $this->access->authorizeTrashed($user, $node, $level);
            } else {
                $this->access->authorize($user, $node, $level);
            }

            return $callback($space, $node);
        });
    }

    private function spaceOf(Node $node): Space
    {
        return Space::findOrFail(Node::withTrashed()->findOrFail($node->id)->space_id);
    }

    private function freshNode(Node $node, Space $space): Node
    {
        $fresh = Node::withTrashed()->where('space_id', $space->id)->whereKey($node->id)->lockForUpdate()->first();
        if (! $fresh) {
            $this->invalid('parent_id', 'Der Eintrag muss im selben Bereich liegen.');
        }

        return $fresh;
    }

    private function destination(User $user, Space $space, ?Node $parent, string $level): ?Node
    {
        if ($parent) {
            $parent = $this->freshNode($parent, $space);
            if ($parent->kind !== 'folder') {
                $this->invalid('parent_id', 'Das Ziel muss ein Ordner im selben Bereich sein.');
            }
        }
        $this->access->authorize($user, $parent ?? $space, $level);

        return $parent;
    }

    private function ensureFolders(User $user, Space $space, ?Node $parent, string $path, ?int &$created = null): ?Node
    {
        $parent = $this->destination($user, $space, $parent, 'edit');
        if ($path === '') {
            return $parent;
        }
        if (strlen($path) > 4096 || str_starts_with($path, '/') || str_ends_with($path, '/') || str_contains($path, '\\')) {
            $this->invalid('path', 'Der Ordnerpfad ist ungültig.');
        }

        $segments = explode('/', $path);
        if (count($segments) > 64 || in_array('', $segments, true)) {
            $this->invalid('path', 'Der Ordnerpfad ist ungültig.');
        }
        foreach ($segments as $segment) {
            $segment = $this->name($segment);
            $query = Node::query()->where('space_id', $space->id)->where('kind', 'folder')
                ->whereRaw('lower(name) = lower(?)', [$segment]);
            $parent ? $query->where('parent_id', $parent->id) : $query->whereNull('parent_id');
            $folder = $query->first();

            if ($folder) {
                $parent = $this->freshNode($folder, $space);
                $this->access->authorize($user, $parent, 'edit');

                continue;
            }

            $parent = Node::create([
                'space_id' => $space->id,
                'parent_id' => $parent?->id,
                'name' => $segment,
                'kind' => 'folder',
            ]);
            $created = ($created ?? 0) + 1;
            $this->audit($user, 'folder.created', $space, $parent);
            $this->collaboration()->notifySubscribers($user, $parent, 'created');
        }

        return $parent;
    }

    /** @param  array{session: int, autosave: bool}|null  $office  set for saves from the office server */
    private function storeVersion(User $user, Space $space, Node $node, UploadedFile $file, ?int $expected, array &$keys, ?array $office = null): Version
    {
        $this->assertFile($node);
        if ($office === null) {
            $this->assertNotEditedInOffice($node);
        }
        $latest = $this->latest($node, $expected);
        [$extension, $mime, $size, $checksum] = $this->validateUpload($file);
        if ($extension !== strtolower(pathinfo($node->name, PATHINFO_EXTENSION))) {
            $this->invalid('file', 'Eine neue Version muss dieselbe Dateiendung haben.');
        }
        if ($latest && hash_equals($latest->checksum, $checksum)) {
            return $latest;
        }
        $replace = $office !== null && $latest && $latest->source === 'office' && $latest->autosave
            && $latest->office_session_id === $office['session'];
        $this->quota($space, $size - ($replace ? $latest->size : 0));
        $key = $this->key($space);
        $keys[] = $key;
        $stream = fopen($file->getRealPath(), 'rb');
        if (! is_resource($stream)) {
            $this->invalid('file', 'Die Datei konnte nicht gelesen werden.');
        }
        try {
            $this->write($key, $stream);
        } finally {
            fclose($stream);
        }
        if (! hash_equals($checksum, hash_file('sha256', $this->disk()->path($key)))) {
            $this->invalid('file', 'Die Prüfsumme der hochgeladenen Datei stimmt nicht.');
        }
        if ($replace) {
            // The replaced autosave goes through the deletion outbox like purged files
            DB::table('x_files_pending_deletions')->insertOrIgnore(['storage_key' => $this->storageKey($latest->storage_key), 'created_at' => now()]);
            $latest->delete();
            DB::afterCommit(function () {
                try {
                    $this->drainDeletions();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
        $version = Version::create([
            'node_id' => $node->id, 'number' => $replace ? $latest->number : ($latest->number ?? 0) + 1, 'storage_key' => $key,
            'checksum' => $checksum, 'size' => $size, 'mime' => $mime, 'extension' => $extension,
            'created_by' => $user->id, 'source' => $office === null ? 'upload' : 'office',
            'office_session_id' => $office['session'] ?? null, 'autosave' => $office['autosave'] ?? false,
        ]);
        $this->audit($user, 'version.created', $space, $node, array_filter([
            'version_id' => $version->id, 'number' => $version->number, 'size' => $size,
            'source' => $office === null ? null : 'office', 'replaced_autosave' => $replace ? $latest->id : null,
        ], fn ($value) => $value !== null));
        if ($latest && ! $version->autosave) {
            $this->collaboration()->notifySubscribers($user, $node, 'version');
        }

        return $version;
    }

    private function latest(Node $node, ?int $expected): ?Version
    {
        $latest = Version::where('node_id', $node->id)->orderByDesc('number')->first();
        if ($expected !== null && $expected !== ($latest->number ?? 0)) {
            $this->invalid('expectedVersion', 'Die Datei wurde inzwischen geändert. Bitte neu laden.');
        }

        return $latest;
    }

    private function validateUpload(UploadedFile $file): array
    {
        $max = self::maxUploadKb() * 1024;
        if (! $file->isValid() || $file->getSize() === false || $file->getSize() > $max) {
            $this->invalid('file', 'Die Datei ist ungültig oder überschreitet das Upload-Limit.');
        }
        $extension = strtolower(pathinfo($this->name($file->getClientOriginalName()), PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions();
        if (! in_array($extension, $allowed, true)) {
            $this->invalid('file', 'Dieser Dateityp ist nicht erlaubt.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $expected = FileResponse::INLINE + FileResponse::DOWNLOAD;
        $matches = $mime === $expected[$extension];
        if (in_array($extension, ['txt', 'csv'], true)) {
            $matches = in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/x-empty', 'inode/x-empty'], true);
        }
        if (in_array($extension, ['doc', 'xls', 'ppt'], true)) {
            $matches = in_array($mime, [$expected[$extension], 'application/x-ole-storage', 'application/CDFV2'], true);
        }
        if (in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
            $matches = in_array($mime, [$expected[$extension], 'application/zip'], true)
                && $this->validOfficeArchive($file, $extension, $expected[$extension]);
        }
        if (! $matches) {
            $this->invalid('file', 'Dateiinhalt und Dateiendung stimmen nicht überein.');
        }

        return [$extension, $mime, (int) $file->getSize(), hash_file('sha256', $file->getRealPath())];
    }

    private function validOfficeArchive(UploadedFile $file, string $extension, string $mime): bool
    {
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }
        try {
            if (in_array($extension, ['odt', 'ods', 'odp'], true)) {
                $stat = $zip->statName('mimetype');

                return $stat && $stat['size'] < 256 && $zip->getFromName('mimetype', 256) === $mime;
            }
            $part = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];

            return $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($part) !== false;
        } finally {
            $zip->close();
        }
    }

    private function assertNotEditedInOffice(Node $node): void
    {
        if (OfficeSession::editing($node->id)) {
            $this->invalid('file', 'Die Datei wird gerade im Online-Office bearbeitet. Bitte später erneut versuchen.');
        }
    }

    private function quota(Space $space, int $bytes): void
    {
        if ($space->quota_bytes !== null && $this->usage($space) + $bytes > $space->quota_bytes) {
            $this->invalid('file', 'Das Speicherlimit dieses Bereichs ist erreicht.');
        }
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || in_array($name, ['.', '..'], true) || mb_strlen($name) > 255
            || preg_match('/[\/\\\\\x00-\x1f\x7f]/u', $name) !== 0 || ! mb_check_encoding($name, 'UTF-8')) {
            $this->invalid('name', 'Bitte einen gültigen Namen ohne Pfad- oder Steuerzeichen angeben.');
        }

        return $name;
    }

    private function assertFile(Node $node): void
    {
        if ($node->kind !== 'file') {
            $this->invalid('node', 'Dieser Eintrag ist keine Datei.');
        }
    }

    private function descendantIds(Node $node): array
    {
        $ids = [$node->id];
        $front = $ids;
        while ($front !== []) {
            $front = Node::withTrashed()->where('space_id', $node->space_id)->whereIn('parent_id', $front)->pluck('id')->all();
            $ids = array_merge($ids, $front);
        }

        return $ids;
    }

    /**
     * "Mit dir geteilt" under the bell (and by mail/push). Only names and the level, never file
     * contents; only recipients who can actually open the target now; failures never undo the share.
     */
    private function notifyShared(User $actor, Space|Node $target, string $subjectType, int $subjectId, string $level): void
    {
        try {
            $ids = match ($subjectType) {
                'user' => [$subjectId],
                'group' => DB::table('x_files_group_members')->where('group_id', $subjectId)->pluck('user_id')->all(),
                'department' => DB::table('group_user')->where('group_id', $subjectId)->pluck('user_id')->all(),
            };
            $recipients = User::withoutGlobalScopes()->whereIn('id', $ids)->whereKeyNot($actor->id)->where('is_active', true)->get()
                ->filter(fn (User $recipient) => $this->access->can($recipient, $target, 'read'));
            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify(new SharedWithYouNotification($target, $actor->name, $level));
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function audit(User $user, string $action, Space $space, ?Node $node = null, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $user->id, 'action' => $action, 'space_id' => $space->id, 'node_id' => $node?->id, 'metadata' => $metadata]);
    }

    private function collaboration(): CollaborationService
    {
        return app(CollaborationService::class);
    }

    /** Local path of a stored version, or null when the file is missing or has the wrong size */
    public function pathOf(Version $version): ?string
    {
        $key = $this->storageKey($version->storage_key);
        try {
            if (! $this->disk()->exists($key)) {
                return null;
            }
        } catch (FilesystemException $e) {
            report($e);

            return null;
        }
        $path = $this->disk()->path($key);

        return filesize($path) === $version->size ? $path : null;
    }

    public function disk(): Filesystem
    {
        // Never accept a public or remote disk configuration for this private local store.
        $config = config('filesystems.disks.dateien');
        if (is_array($config) && ($config['driver'] ?? null) === 'local'
            && ($config['visibility'] ?? 'private') === 'private'
            && ($config['root'] ?? null) === storage_path('app/private/dateien')) {
            return Storage::disk('dateien');
        }

        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private/dateien'), 'visibility' => 'private', 'throw' => true]);
    }

    private function key(Space $space): string
    {
        return Str::uuid().'/'.Str::uuid();
    }

    private function storageKey(string $key): string
    {
        if (! preg_match('/\A[0-9a-f-]{36}\/[0-9a-f-]{36}\z/D', $key)) {
            $this->invalid('file', 'Ungültiger Speicherschlüssel.');
        }

        return $key;
    }

    private function write(string $key, mixed $stream): void
    {
        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');
        // A committed savepoint can still be rolled back by any enclosing transaction.
        foreach ($transactions->getPendingTransactions() as $transaction) {
            if ($transaction->connection === DB::connection()->getName()) {
                $transaction->addCallbackForRollback(fn () => $this->cleanup([$key]));
            }
        }
        $disk = $this->disk();
        $stage = '.staging/'.Str::uuid();
        try {
            if (! $disk->put($stage, $stream, ['visibility' => 'private']) || ! $disk->move($stage, $key)) {
                $this->invalid('file', 'Die Datei konnte nicht gespeichert werden.');
            }
        } finally {
            $disk->delete($stage);
        }
    }

    private function cleanup(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->disk()->delete($this->storageKey($key));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Works through the deletion outbox. Failures stay queued with exponential backoff
     * (2^attempts minutes, at most a day); $force ignores the backoff (admin "retry now").
     *
     * @return array{deleted: int, failed: int, kept: int}
     */
    public function drainPendingDeletions(bool $force = false): array
    {
        $result = ['deleted' => 0, 'failed' => 0, 'kept' => 0];
        $query = DB::table('x_files_pending_deletions')->orderBy('id')->limit(500);
        if (! $force) {
            $query->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
        }
        foreach ($query->get() as $entry) {
            try {
                $key = $this->storageKey($entry->storage_key);
                if (Version::where('storage_key', $key)->exists()) {
                    // Still referenced (e.g. restored from a backup): never delete live content
                    $result['kept']++;

                    continue;
                }
                $this->disk()->delete($key);
                DB::table('x_files_pending_deletions')->where('id', $entry->id)->delete();
                $result['deleted']++;
            } catch (Throwable $e) {
                report($e);
                $attempts = (int) $entry->attempts + 1;
                DB::table('x_files_pending_deletions')->where('id', $entry->id)->update([
                    'attempts' => $attempts,
                    'next_attempt_at' => now()->addMinutes(min(1440, 2 ** min($attempts, 11))),
                    'last_error' => Str::limit(class_basename($e).': '.$e->getMessage(), 250),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function drainDeletions(): void
    {
        $this->drainPendingDeletions(true);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
