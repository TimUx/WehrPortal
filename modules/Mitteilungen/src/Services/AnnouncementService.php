<?php

namespace Modules\Mitteilungen\src\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Notifications\AnnouncementNotification;
use Throwable;

class AnnouncementService
{
    /**
     * @param  array{title: string, body?: ?string, important: bool, visible_from?: mixed, visible_until?: mixed}  $data
     * @param  int[]  $groupIds  audience; empty = everyone
     */
    public function save(?Announcement $announcement, array $data, array $groupIds, User $by, bool $mail = false): Announcement
    {
        $announcement = DB::transaction(function () use ($announcement, $data, $groupIds, $by) {
            $announcement ??= new Announcement(['created_by' => $by->id]);
            $announcement->fill($data)->save();
            $announcement->groups()->sync($groupIds);

            return $announcement;
        });

        if ($mail) {
            $this->mail($announcement);
        }

        return $announcement;
    }

    /** Everyone it is meant for gets it by mail; a mail problem is logged, not thrown */
    public function mail(Announcement $announcement): int
    {
        $groupIds = $announcement->groups()->pluck('groups.id');
        $sent = 0;

        User::active()
            ->when($groupIds->isNotEmpty(), fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', $groupIds)))
            ->each(function (User $user) use ($announcement, &$sent) {
                try {
                    $user->notify(new AnnouncementNotification($announcement));
                    $sent++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $sent;
    }

    public function dismiss(Announcement $announcement, User $user): void
    {
        if (! $announcement->important) {
            $announcement->dismissedBy()->syncWithoutDetaching([$user->id]);
        }
    }
}
