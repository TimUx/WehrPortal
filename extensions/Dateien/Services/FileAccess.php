<?php

namespace Extensions\Dateien\Services;

use App\Models\User;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Permission;
use Extensions\Dateien\Models\Space;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class FileAccess
{
    public const LEVELS = ['read' => 1, 'edit' => 2, 'manage' => 3];

    public function member(User $user): User
    {
        $fresh = User::withoutGlobalScopes()->find($user->getKey());
        $module = DB::table('modules')->where('key', 'dateien')->where('is_active', true)->first();
        if (! $fresh || ! $fresh->is_active || ! $module
            || (! $fresh->is_admin && ! $module->auto_assign && ! $fresh->moduleAccess()->where('module', 'dateien')->exists())) {
            throw new AuthorizationException('Kein Zugriff auf Dateien.');
        }

        return $fresh;
    }

    public function level(User $user, Space|Node $target): ?string
    {
        return $this->resolve($user, $target, false);
    }

    public function can(User $user, Space|Node $target, string $level): bool
    {
        if (! isset(self::LEVELS[$level])) {
            return false;
        }
        $actual = $this->level($user, $target);

        return $actual !== null && self::LEVELS[$actual] >= self::LEVELS[$level];
    }

    public function authorize(User $user, Space|Node $target, string $level): void
    {
        if (! $this->can($user, $target, $level)) {
            throw new AuthorizationException('Keine Berechtigung für diese Datei.');
        }
    }

    public function authorizeTrashed(User $user, Node $target, string $level = 'manage'): void
    {
        $actual = $this->resolve($user, $target, true);
        if (! isset(self::LEVELS[$level]) || $actual === null || self::LEVELS[$actual] < self::LEVELS[$level]) {
            throw new AuthorizationException('Keine Berechtigung für diesen Papierkorb-Eintrag.');
        }
    }

    private function resolve(User $user, Space|Node $target, bool $allowTrashed): ?string
    {
        try {
            $user = $this->member($user);
        } catch (AuthorizationException) {
            return null;
        }

        $chain = [];
        if ($target instanceof Node) {
            // The whole ancestor chain in one query instead of one query per level
            $rows = DB::select(<<<'SQL'
                WITH RECURSIVE chain AS (
                    SELECT id, parent_id, space_id, deleted_at, inherit_permissions, 0 AS depth FROM x_files_nodes WHERE id = ?
                    UNION ALL
                    SELECT n.id, n.parent_id, n.space_id, n.deleted_at, n.inherit_permissions, c.depth + 1
                    FROM x_files_nodes n JOIN chain c ON n.id = c.parent_id WHERE c.depth < 10000
                ) SELECT id, parent_id, space_id, deleted_at, inherit_permissions FROM chain ORDER BY depth
                SQL, [$target->getKey()]);
            if ($rows === []) {
                return null;
            }
            $space = Space::find($rows[0]->space_id);
            $seen = [];
            foreach ($rows as $row) {
                if (isset($seen[$row->id]) || ! $space || (int) $row->space_id !== $space->id
                    || ($row->deleted_at !== null && (! $allowTrashed || $chain !== []))) {
                    return null;
                }
                $seen[$row->id] = true;
                $chain[] = $row;
            }
            if (end($chain)->parent_id !== null) {
                return null;
            }
        } else {
            /** @var Space|null $space */
            $space = Space::find($target->getKey());
        }

        if (! $space) {
            return null;
        }
        if ($user->is_admin) {
            return 'manage';
        }
        if (config('locations.enabled') && $space->ext_location_id !== null && ! (new LocationAccess)->canSee($user, (int) $space->ext_location_id)) {
            return null;
        }

        $groups = DB::table('x_files_group_members')->join('x_files_groups', 'x_files_groups.id', '=', 'x_files_group_members.group_id')
            ->where('user_id', $user->id);
        if (config('locations.enabled')) {
            $locations = (new LocationAccess)->visibleLocationIds($user);
            $groups->where(fn ($q) => $q->whereNull('x_files_groups.ext_location_id')->orWhereIn('x_files_groups.ext_location_id', $locations));
        }
        $groups = $groups->pluck('group_id')->all();
        $nodes = [];
        $inherit = true;
        foreach ($chain as $node) {
            $nodes[] = (int) $node->id;
            if (! $node->inherit_permissions) {
                $inherit = false;
                break;
            }
        }
        $best = Permission::query()
            ->where(function ($query) use ($nodes, $inherit, $space) {
                $query->where(fn ($q) => $q->where('target_type', 'node')->whereIn('target_id', $nodes ?: [0]));
                if ($inherit) {
                    $query->orWhere(fn ($q) => $q->where('target_type', 'space')->where('target_id', $space->id));
                }
            })
            ->where(function ($query) use ($user, $groups) {
                $query->where(fn ($q) => $q->where('subject_type', 'user')->where('subject_id', $user->id))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'group')->whereIn('subject_id', $groups));
            })->pluck('level')->map(fn ($level) => self::LEVELS[$level] ?? 0)->max() ?? 0;

        return array_search($best, self::LEVELS, true) ?: null;
    }
}
