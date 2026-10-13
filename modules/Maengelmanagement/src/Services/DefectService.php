<?php

namespace Modules\Maengelmanagement\src\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Enums\DamageStatus;
use Modules\Ausruestung\src\Models\Damage;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\DefectEvent;
use Modules\Maengelmanagement\src\Notifications\DefectDigestNotification;
use Modules\Maengelmanagement\src\Notifications\DefectReportedNotification;
use Modules\Maengelmanagement\src\Notifications\DefectUpdatedNotification;
use Throwable;

/** The only place that changes defects: every change leaves a history entry and notifies the people concerned */
class DefectService
{
    public function __construct(private readonly DefectPhotoService $photos) {}

    /**
     * A Gerät of the Ausrüstungsverwaltung (equipment_id) also gets a Schaden there, so it is marked
     * defective and the Gerätewarte know; an object with a Zuständigen is assigned right away.
     *
     * @param  array{device_id?: ?int, vehicle_id?: ?int, equipment_id?: ?int, location?: ?string, title: string, description?: ?string, urgency: string}  $data
     * @param  string[]  $photoPaths  local paths of the uploaded images
     */
    public function report(User $reporter, array $data, array $photoPaths = []): Defect
    {
        $equipment = ! empty($data['equipment_id']) && empty($data['device_id']) && empty($data['vehicle_id']) && app(ModuleRegistry::class)->isActive('ausruestung')
            ? Equipment::find((int) $data['equipment_id']) : null;
        $defect = DB::transaction(function () use ($reporter, $data, $photoPaths, $equipment) {
            $defect = Defect::create([
                'equipment_id' => $equipment?->id,
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

            $responsible = $defect->device?->responsible;
            if ($responsible && $responsible->is_active) {
                $defect->update(['assigned_to' => $responsible->id]);
                $this->log($defect, $reporter, DefectEvent::ASSIGNED, null, $responsible->name, 'zuständig für '.$defect->device->label());
            }

            return $defect;
        });

        $this->managers()
            ->reject(fn (User $manager) => $manager->is($reporter))
            ->each(fn (User $manager) => $this->send($manager, new DefectReportedNotification($defect)));
        if ($defect->assignee && ! $defect->assignee->is($reporter) && ! $this->managers()->contains('id', $defect->assigned_to)) {
            $this->send($defect->assignee, new DefectUpdatedNotification($defect, 'Dir wurde ein Mangel zugewiesen', $reporter));
        }
        if ($equipment) {
            $this->damageInAusruestung($defect, $reporter);
        }

        return $defect;
    }

    /** Verantwortliche and whoever the defect is assigned to work on it */
    public static function canEdit(User $user, Defect $defect): bool
    {
        return $user->canManageMaengel() || ($user->is_active && $defect->assigned_to === $user->id);
    }

    /** A Rückfrage to the reporter: it stays open (and visible) until they answer */
    public function ask(Defect $defect, User $by, string $question, array $photoPaths = []): void
    {
        $this->assertCanEdit($by, $defect);
        DB::transaction(function () use ($defect, $by, $question, $photoPaths) {
            $event = $this->log($defect, $by, DefectEvent::QUESTION, body: $question);
            $this->storePhotos($defect, $event, $photoPaths);
            $defect->update(['question_open_at' => now()]);
        });
        if (! $defect->reporter->is($by)) {
            $this->send($defect->reporter, new DefectUpdatedNotification($defect, 'Rückfrage zu deinem Mangel', $by, $question));
        }
    }

    /**
     * A comment, optionally with photos. From the reporter while a Rückfrage is open it is the
     * answer: the Rückfrage closes and whoever asked hears about it.
     */
    public function comment(Defect $defect, User $by, string $body, array $photoPaths = []): void
    {
        $isAnswer = $defect->question_open_at !== null && $by->id === $defect->reporter_id;
        $asker = $isAnswer ? $defect->events()->where('type', DefectEvent::QUESTION)->latest('id')->first()?->user : null;
        DB::transaction(function () use ($defect, $by, $body, $photoPaths, $isAnswer) {
            $event = $this->log($defect, $by, $isAnswer ? DefectEvent::ANSWER : DefectEvent::COMMENT, body: $body);
            $this->storePhotos($defect, $event, $photoPaths);
            if ($isAnswer) {
                $defect->update(['question_open_at' => null]);
            }
        });

        if ($isAnswer) {
            collect([$asker, $defect->assignee])->filter()->unique('id')->reject(fn (User $u) => $u->is($by))
                ->each(fn (User $u) => $this->send($u, new DefectUpdatedNotification($defect, 'Antwort auf deine Rückfrage', $by, $body)));

            return;
        }
        $this->tellInvolved($defect, $by, 'Neuer Kommentar', $body);
    }

    /** The reporter says it is not fixed after all (within REOPEN_DAYS of closing) */
    public function reopen(Defect $defect, User $by, string $reason, array $photoPaths = []): void
    {
        if (! $defect->canReopen($by)) {
            throw ValidationException::withMessages(['reopen' => 'Wieder öffnen kann nur, wer den Mangel gemeldet hat, bis '.Defect::REOPEN_DAYS.' Tage nach dem Abschluss.']);
        }
        DB::transaction(function () use ($defect, $by, $reason, $photoPaths) {
            $event = $this->log($defect, $by, DefectEvent::REOPENED, DefectStatus::Resolved->value, DefectStatus::Open->value, $reason);
            $this->storePhotos($defect, $event, $photoPaths);
            $defect->update(['status' => DefectStatus::Open, 'resolved_at' => null, 'reminded_at' => null]);
        });
        $to = $defect->assignee ? collect([$defect->assignee]) : $this->managers();
        $to->reject(fn (User $u) => $u->is($by))->each(fn (User $u) => $this->send($u, new DefectUpdatedNotification($defect, 'Doch nicht behoben', $by, $reason)));
        if ($defect->equipment_id) {
            $this->damageInAusruestung($defect, $by, 'wieder geöffnet: ');
        }
    }

    /**
     * Daily, to the Verantwortlichen: defects nobody took on for 2 days, Rückfragen without answer
     * for 7 days and defects without any change for 30 days. Each defect at most once a week.
     */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive('maengelmanagement')) {
            return 0;
        }
        $ids = collect(DB::select(
            "UPDATE mm_defects SET reminded_at = now() WHERE status IN ('open', 'in_progress')
               AND (reminded_at IS NULL OR reminded_at < now() - interval '7 days')
               AND ((assigned_to IS NULL AND created_at < now() - interval '2 days')
                 OR (question_open_at IS NOT NULL AND question_open_at < now() - interval '7 days')
                 OR updated_at < now() - interval '30 days')
             RETURNING id"
        ))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        $defects = Defect::with(['device', 'vehicle', 'assignee'])->whereIn('id', $ids)
            ->orderByRaw("case urgency when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")->get();
        $this->managers()->each(fn (User $u) => $this->send($u, new DefectDigestNotification($defects)));

        return $defects->count();
    }

    /** The Gerät of the Ausrüstungsverwaltung gets a Schaden (defective, Gerätewarte informed) */
    private function damageInAusruestung(Defect $defect, User $by, string $prefix = ''): void
    {
        try {
            $equipment = Equipment::find($defect->equipment_id);
            if ($equipment && app(ModuleRegistry::class)->isActive('ausruestung')) {
                $damage = app(EquipmentService::class)->reportDamage($equipment, $by, 'Mängelmanagement: '.$prefix.$defect->title.(filled($defect->description) ? ' – '.$defect->description : ''));
                $defect->update(['ar_damage_id' => $damage->id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function storePhotos(Defect $defect, DefectEvent $event, array $paths): void
    {
        foreach (array_slice($paths, 0, DefectPhotoService::MAX_PHOTOS) as $path) {
            $this->photos->store($defect, $path, $event->id);
        }
    }

    private function assertCanEdit(User $user, Defect $defect): void
    {
        if (! self::canEdit($user, $defect)) {
            throw ValidationException::withMessages(['defect' => 'Das dürfen nur Verantwortliche und wer zuständig ist.']);
        }
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
            if (! $status->isActive()) {
                $defect->update(['question_open_at' => null]);
            }
        });

        // Fixed: the Schaden in the Ausrüstungsverwaltung is repaired too (the Gerät comes back when nothing else is open)
        if ($status === DefectStatus::Resolved && $defect->ar_damage_id && app(ModuleRegistry::class)->isActive('ausruestung')) {
            try {
                $damage = Damage::find($defect->ar_damage_id);
                if ($damage && $damage->status->isOpen()) {
                    app(EquipmentService::class)->updateDamage($damage, $by, DamageStatus::Repaired, 'im Mängelmanagement erledigt'.(filled($note) ? ': '.$note : ''));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

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

    private function log(Defect $defect, User $by, string $type, ?string $from = null, ?string $to = null, ?string $body = null): DefectEvent
    {
        return $defect->events()->create([
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
