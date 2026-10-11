<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserModulePreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The modules a Kamerad can use, in their order: by default the most used first, or – locked – the
 * order the Kamerad set. Hidden ones stay usable and stay in the app launcher; they only disappear
 * from the start page.
 */
class ModuleOverview
{
    /** The usage score halves within this many days, so old habits fade */
    public const HALF_LIFE_DAYS = 30;

    /** Visits to a module within this many minutes count once */
    public const VISIT_MINUTES = 30;

    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return array<string, array{name: string, description: string, route: string, url: string, hidden: bool, order: int, score: float}> */
    public function for(User $user): array
    {
        $preferences = UserModulePreference::where('user_id', $user->id)->get()->keyBy('module_key');
        $position = array_flip(array_keys($this->registry->all()));
        $now = now();

        $modules = collect($this->registry->all())
            ->filter(fn ($def, $key) => $this->registry->isActive($key) && $user->canUseModule($key))
            ->map(fn ($def, $key) => $def + [
                'url' => str_starts_with($def['route'], '/') ? url($def['route']) : route($def['route']),
                'hidden' => (bool) $preferences->get($key)?->hidden,
                'order' => $preferences->get($key)?->sort_order ?? 1000 + $position[$key],
                'score' => self::decayed((float) $preferences->get($key)?->usage_score, $preferences->get($key)?->used_at, $now),
            ]);

        return ($user->modules_locked ? $modules->sortBy('order') : $modules->sortBy([['score', 'desc'], ['order', 'asc']]))->all();
    }

    /**
     * Counts one use of a module. Written without model events: it is no change worth a log entry.
     * Repeated page views within VISIT_MINUTES are one use.
     */
    public function recordUse(User $user, string $key): void
    {
        $now = now();
        $table = DB::table('user_module_preferences');
        $row = (clone $table)->where('user_id', $user->id)->where('module_key', $key)->first();

        if ($row === null) {
            $table->insertOrIgnore(['user_id' => $user->id, 'module_key' => $key, 'usage_score' => 1, 'used_at' => $now, 'hidden' => false, 'created_at' => $now, 'updated_at' => $now]);

            return;
        }

        if ($row->used_at && Carbon::parse($row->used_at)->gt($now->copy()->subMinutes(self::VISIT_MINUTES))) {
            return;
        }

        $table->where('id', $row->id)->update(['usage_score' => self::decayed((float) $row->usage_score, $row->used_at, $now) + 1, 'used_at' => $now]);
    }

    /** Saves the order as shown, given as module keys from first to last; a sorted start page is locked */
    public function reorder(User $user, array $keys): void
    {
        foreach (array_values($keys) as $index => $key) {
            UserModulePreference::updateOrCreate(['user_id' => $user->id, 'module_key' => $key], ['sort_order' => $index]);
        }

        $this->setLocked($user, true);
    }

    public function setLocked(User $user, bool $locked): void
    {
        if ($user->modules_locked !== $locked) {
            $user->forceFill(['modules_locked' => $locked])->save();
        }
    }

    public function setHidden(User $user, string $key, bool $hidden): void
    {
        UserModulePreference::updateOrCreate(['user_id' => $user->id, 'module_key' => $key], ['hidden' => $hidden]);
    }

    /** Back to the start: sorted by use, nothing hidden. The usage stays. */
    public function reset(User $user): void
    {
        UserModulePreference::where('user_id', $user->id)->update(['sort_order' => null, 'hidden' => false]);
        $this->setLocked($user, false);
    }

    private static function decayed(float $score, mixed $usedAt, Carbon $now): float
    {
        if ($score <= 0 || $usedAt === null) {
            return 0.0;
        }

        $days = max(0, Carbon::parse($usedAt)->diffInSeconds($now)) / 86400;

        return $score * 0.5 ** ($days / self::HALF_LIFE_DAYS);
    }
}
