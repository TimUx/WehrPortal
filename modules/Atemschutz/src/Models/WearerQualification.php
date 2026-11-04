<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class WearerQualification extends Model
{
    public const KINDS = [
        'agt' => 'Atemschutzgeräteträger (AGT)',
        'csa' => 'Chemikalienschutzanzug (CSA)',
        'other' => 'Weitere Qualifikation',
    ];

    protected $table = 'as_wearer_qualifications';

    protected $fillable = [
        'wearer_id',
        'kind',
        'title',
        'qualified_on',
        'valid_until',
        'issuer',
        'notes',
        'proof_filename',
        'proof_size',
        'proof_data',
        'is_legacy',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'qualified_on' => 'date',
            'valid_until' => 'date',
            'proof_size' => 'integer',
            'is_legacy' => 'boolean',
        ];
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

    public function isValidOn(?Carbon $day = null): bool
    {
        $day ??= Carbon::parse(now('Europe/Berlin')->toDateString());

        return $this->valid_until === null || Carbon::parse((string) $this->valid_until)->gte($day);
    }
}
