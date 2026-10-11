<?php

namespace Modules\Uebungsdienste\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One Übungsdienst: date and start (Berlin time), place, topic and who is responsible */
class Service extends Model
{
    protected $table = 'ud_services';

    protected $fillable = ['date', 'starts_at', 'location', 'topic'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function responsible(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ud_service_user', 'service_id', 'user_id')->orderBy('name');
    }

    /** "Di, 14.10.2026" */
    public function dateLabel(): string
    {
        return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][$this->date->dayOfWeek].', '.$this->date->format('d.m.Y');
    }
}
