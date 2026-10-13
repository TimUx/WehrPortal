<?php

namespace Modules\Lehrgaenge\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The documentation of an absolvierter Lehrgang that was deleted with a reason */
class Deletion extends Model
{
    protected $table = 'lq_deletions';

    protected $fillable = ['user_id', 'course_name', 'completed_on', 'reason', 'deleted_by'];

    protected function casts(): array
    {
        return ['completed_on' => 'date'];
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
