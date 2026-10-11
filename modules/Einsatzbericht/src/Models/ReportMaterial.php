<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What an Einsatz used; name, category and unit are copied from the catalog (or typed freely) */
class ReportMaterial extends Model
{
    protected $table = 'eb_report_materials';

    protected $fillable = ['report_id', 'material_id', 'name', 'category', 'unit', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function categoryLabel(): string
    {
        return Material::CATEGORIES[$this->category] ?? $this->category;
    }

    public function unitLabel(): string
    {
        return Material::UNITS[$this->unit] ?? $this->unit;
    }

    /** "12,5 Liter" */
    public static function amount(float $quantity, string $unit): string
    {
        return rtrim(rtrim(number_format($quantity, 2, ',', '.'), '0'), ',').' '.(Material::UNITS[$unit] ?? $unit);
    }
}
