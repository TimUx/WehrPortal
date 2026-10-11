<?php

namespace Modules\Einsatzbericht\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An Einsatzkraft on one vehicle (or in the Bereitschaft) with function and Atemschutz */
class CrewMember extends Model
{
    /**
     * Functions and where they count in the Stärke Führung/Unterführer/Mannschaft:
     * f = Zugführer and above, u = Gruppen-/Staffelführer, m = Mannschaft.
     */
    public const FUNCTIONS = [
        'einsatzleiter' => ['Einsatzleiter', 'f'],
        'zugfuehrer' => ['Zugführer', 'f'],
        'gruppenfuehrer' => ['Gruppenführer', 'u'],
        'staffelfuehrer' => ['Staffelführer', 'u'],
        'maschinist' => ['Maschinist', 'm'],
        'melder' => ['Melder', 'm'],
        'angriffstrupp' => ['Angriffstrupp', 'm'],
        'wassertrupp' => ['Wassertrupp', 'm'],
        'schlauchtrupp' => ['Schlauchtrupp', 'm'],
        'sicherheitstrupp' => ['Sicherheitstrupp', 'm'],
        'einsatzkraft' => ['Einsatzkraft', 'm'],
    ];

    protected $table = 'eb_report_crew';

    protected $attributes = ['function' => 'einsatzkraft', 'breathing_apparatus' => false];

    protected $fillable = ['report_id', 'report_vehicle_id', 'user_id', 'function', 'breathing_apparatus'];

    protected function casts(): array
    {
        return ['breathing_apparatus' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function reportVehicle(): BelongsTo
    {
        return $this->belongsTo(ReportVehicle::class, 'report_vehicle_id');
    }

    public function functionLabel(): string
    {
        return self::FUNCTIONS[$this->function][0] ?? $this->function;
    }

    public function level(): string
    {
        return self::FUNCTIONS[$this->function][1] ?? 'm';
    }
}
