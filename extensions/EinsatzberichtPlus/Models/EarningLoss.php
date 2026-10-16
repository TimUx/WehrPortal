<?php

namespace Extensions\EinsatzberichtPlus\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Einsatzbericht\src\Models\Report;

/** Verdienstausfall of an Einsatzkraft: the employer and the hours missed, with the state of the claim */
class EarningLoss extends Model
{
    public const STATUS = ['open' => 'Offen', 'requested' => 'Erstattung beantragt', 'settled' => 'Erledigt'];

    protected $table = 'x_ebp_earning_losses';

    protected $attributes = ['status' => 'open'];

    protected $fillable = ['report_id', 'user_id', 'employer', 'hours', 'status', 'note'];

    protected function casts(): array
    {
        return ['employer' => 'encrypted', 'note' => 'encrypted', 'hours' => 'decimal:2'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
