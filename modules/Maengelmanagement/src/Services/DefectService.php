<?php

namespace Modules\Maengelmanagement\src\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\DefectEvent;
use Modules\Maengelmanagement\src\Notifications\DefectReportedNotification;
use Modules\Maengelmanagement\src\Notifications\DefectUpdatedNotification;
use Throwable;

/** The only place that changes defects: every change leaves a history entry and notifies the people concerned */
class DefectService
{
    public function __construct(private readonly DefectPhotoService $photos) {}

    /**
     * @param  array{device_id?: ?int, vehicle_id?: ?int, location?: ?string, title: string, description?: ?string, urgency: string}  $data
     * @param  string[]  $photoPaths  local paths of the uploaded images
     */
    public function report(User $reporter, array $data, array $photoPaths = []): Defect
    {
        $defect = DB::transaction(function () use ($reporter, $data, $photoPaths) {
            $defect = Defect::create([
                'device_id' => $data['device_id'] ?? null,
                'vehicle_id' => empty($data['device_id']) ? ($data['vehicle_id'] ?? null) : null,
                'location' => $data['location'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'urgency' => $data['urgency'],
                'status' => DefectStatus::Open,
                'reporter_id' => $reporter->id,
            ]);

            foreach (array_slice($photoPaths, 0, DefectPhotoService::MAX_PHOTOS) as $path) {
                $this->photos->store($defect, $path);
            }

            $this->log($defect, $reporter, DefectEvent::REPORTED);

            return $defect;
        });

        $this->managers()
            ->reject(fn (User $manager) => $manager->is($reporter))
            ->each(fn (User $manager) => $this->send($manager, new DefectReportedNotification($defect)));

        return $defect;
    }

    public function setStatus(Defect $defect, User $by, DefectStatus $status, ?string $note = null): void
    {
        if ($defect->status === $status && blank($note)) {
            return;
        }

        $from = $defect->status;

        DB::transaction(function () use ($defect, $by, $status, $note, $from) {
            if ($from !== $status) {
                $defect->update([
                    'status' => $status,
                    'resolved_at' => $status->isActive() ? null : ($defect->resolved_at ?? now()),
                ]);
            }

            $this->log($defect, $by, DefectEvent::STATUS, $from->value, $status->value, $note);
        });

        $this->tellInvolved($defect, $by, 'Status: '.$status->label(), $note);
    }

    public function setUrgency(Defect $defect, User $by, Urgency $urgency): void
    {
        if ($defect->urgency === $urgency) {
            return;
        }

        $from = $defect->urgency;
        $defect->update(['urgency' => $urgency]);
        $this->log($defect, $by, DefectEvent::URGENCY, $from->value, $urgency->value);
    }

    public function assign(Defect $defect, User $by, ?User $to): void
    {
        if ($defect->assigned_to === $to?->id) {
            return;
        }

        $from = $defect->assignee?->name;
        $defect->update(['assigned_to' => $to?->id]);
        $this->log($defect, $by, DefectEvent::ASSIGNED, $from, $to?->name);

        if ($to !== null && ! $to->is($by)) {
            $this->send($to, new DefectUpdatedNotification($defect, 'Dir wurde ein Mangel zugewiesen', $by));
        }
    }

    public function comment(Defect $defect, User $by, string $body): void
    {
        $this->log($defect, $by, DefectEvent::COMMENT, body: $body);
        $this->tellInvolved($defect, $by, 'Neuer Kommentar', $body);
    }

    /** Users who look after defects: holders of the role, or the admins if nobody holds it */
    public function managers(): Collection
    {
        $managers = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'maengelmanagement')->where('role', 'verantwortlicher'))
            ->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    /** Reporter and assignee hear about changes made by someone else */
    private function tellInvolved(Defect $defect, User $by, string $headline, ?string $text): void
    {
        collect([$defect->reporter, $defect->assignee])
            ->filter()
            ->unique('id')
            ->reject(fn (User $user) => $user->is($by))
            ->each(fn (User $user) => $this->send($user, new DefectUpdatedNotification($defect, $headline, $by, $text)));
    }

    private function log(Defect $defect, User $by, string $type, ?string $from = null, ?string $to = null, ?string $body = null): void
    {
        $defect->events()->create([
            'user_id' => $by->id,
            'type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'body' => filled($body) ? $body : null,
        ]);
    }

    /** The change is stored either way; a mail server problem ends up in the log */
    private function send(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
