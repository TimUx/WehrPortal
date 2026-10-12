<?php

namespace Modules\Objektplaene\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded file of a document; never changed, a new upload is a new version */
class Version extends Model
{
    /** Everything but the file itself */
    public const META = ['op_versions.id', 'op_versions.document_id', 'version', 'name', 'mime', 'size', 'note', 'valid_from', 'uploaded_by', 'op_versions.created_at'];

    public const UPDATED_AT = null;

    protected $table = 'op_versions';

    protected $fillable = ['document_id', 'version', 'name', 'mime', 'size', 'data', 'note', 'valid_from', 'uploaded_by'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'size' => 'integer', 'valid_from' => 'date'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    /** "2,4 MB" */
    public function sizeLabel(): string
    {
        return $this->size >= 1048576 ? number_format($this->size / 1048576, 1, ',', '.').' MB' : max(1, (int) round($this->size / 1024)).' KB';
    }

    public function url(): string
    {
        return route('objektplaene.file', $this->id);
    }
}
