<?php

namespace Extensions\Anwesenheit\Models;

use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Anwesenheitsliste: who took part in what, when and for how long. Standalone, never bound to a Kalender event. */
class AttendanceList extends Model
{
    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    protected $table = 'x_att_lists';

    protected $attributes = ['status' => self::DRAFT, 'kind' => 'uebung', 'guests' => 0, 'participant_count' => 0, 'duration_minutes' => 0];

    protected $fillable = ['ext_location_id', 'title', 'kind', 'status', 'starts_at', 'ends_at', 'duration_minutes', 'leader_id', 'guests',
        'participant_count', 'notes', 'recipients', 'template_id', 'source_event_id', 'submitted_at', 'mailed_at', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'submitted_at' => 'datetime', 'mailed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Extensions\ models are not picked up by the core activity log, so changes are written here
        $log = fn (string $event) => function (AttendanceList $list) use ($event) {
            $changes = $event === 'updated' ? collect($list->getChanges())->except(['updated_at', 'notes'])
                ->mapWithKeys(fn ($new, $field) => [$field => [$list->getOriginal($field), $new]])->all() : [];
            if ($event !== 'updated' || $changes !== []) {
                app(ActivityRecorder::class)->record($event, static::class, $list->getKey(), $list->title, $changes);
            }
        };
        static::created($log('created'));
        static::updated($log('updated'));
        static::deleted($log('deleted'));
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'x_att_participants', 'list_id', 'user_id')->orderBy('users.name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AttendanceFile::class, 'list_id');
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    public function kindLabel(): string
    {
        return config('anwesenheit.kinds')[$this->kind] ?? $this->kind;
    }

    /** "1 Std. 30 Min." */
    public function durationLabel(): string
    {
        return self::formatMinutes($this->duration_minutes);
    }

    public static function formatMinutes(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours ? $hours.' Std. ' : '').($rest || ! $hours ? $rest.' Min.' : ''));
    }

    /** @return list<string> the addresses typed into recipients (comma, semicolon or line separated) */
    public static function parseRecipients(?string $text): array
    {
        return collect(preg_split('/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }
}
