<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WearerExamination extends Model
{
    protected $table = 'as_wearer_examinations';

    protected $fillable = ['wearer_id', 'examined_on', 'valid_until', 'is_legacy', 'recorded_by'];

    protected function casts(): array
    {
        return ['examined_on' => 'date', 'valid_until' => 'date', 'is_legacy' => 'boolean'];
    }

    /** @return BelongsTo<Wearer, $this> */
    public function wearer(): BelongsTo
    {
        return $this->belongsTo(Wearer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
