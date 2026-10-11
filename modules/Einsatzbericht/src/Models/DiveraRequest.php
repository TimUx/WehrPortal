<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One request of the DIVERA webhook, kept unchanged for checking the field mapping */
class DiveraRequest extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    public const STATUSES = [
        'pending' => 'In Arbeit', 'created' => 'Einsatz angelegt', 'attached' => 'An bestehenden Einsatz gehängt',
        'filtered' => 'Gefiltert', 'disabled' => 'Webhook aus', 'error' => 'Fehler',
    ];

    public $timestamps = false;

    protected $table = 'eb_divera_requests';

    protected $fillable = ['received_at', 'method', 'content_type', 'ip', 'body', 'status', 'message', 'report_id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
