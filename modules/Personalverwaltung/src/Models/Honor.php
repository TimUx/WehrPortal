<?php

namespace Modules\Personalverwaltung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Ehrung a Kamerad received */
class Honor extends Model
{
    protected $table = 'pm_honors';

    protected $fillable = ['user_id', 'honor_type_id', 'title', 'awarded_on', 'awarded_by', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['awarded_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(HonorType::class, 'honor_type_id');
    }
}
