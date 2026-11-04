<?php

namespace Modules\Atemschutz\src\Services;

use App\Models\User;
use App\Support\FileResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Models\WearerExamination;
use Modules\Atemschutz\src\Models\WearerQualification;

class WearerService
{
    public const MAX_PROOF_KB = 25600;

    public function recordExamination(Wearer $wearer, array $data, User $actor): WearerExamination
    {
        if (! MonitorService::canCareFor($actor, $wearer->user_id)) {
            abort(403);
        }

        $validated = Validator::make($data, [
            'examined_on' => ['required', 'date', 'before_or_equal:'.ReadinessService::today()->toDateString()],
            'valid_until' => ['required', 'date', 'after_or_equal:examined_on'],
        ])->validate();

        return DB::transaction(function () use ($wearer, $validated, $actor) {
            Wearer::query()->whereKey($wearer->id)->lockForUpdate()->firstOrFail();

            return WearerExamination::query()->create([
                'wearer_id' => $wearer->id,
                'examined_on' => $validated['examined_on'],
                'valid_until' => $validated['valid_until'],
                'recorded_by' => $actor->id,
            ]);
        });
    }

    public function recordQualification(Wearer $wearer, array $data, User $actor): WearerQualification
    {
        if (! MonitorService::canCareFor($actor, $wearer->user_id)) {
            abort(403);
        }

        $validated = Validator::make($data, [
            'kind' => ['required', Rule::in(array_keys(WearerQualification::KINDS))],
            'title' => ['nullable', 'string', 'max:180', Rule::requiredIf(($data['kind'] ?? null) === 'other')],
            'qualified_on' => ['nullable', 'date', 'before_or_equal:'.ReadinessService::today()->toDateString()],
            'valid_until' => ['nullable', 'date', 'after_or_equal:qualified_on'],
            'issuer' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:500'],
            'proof' => ['nullable', 'file', 'max:'.self::MAX_PROOF_KB],
        ])->validate();

        if ($validated['kind'] === 'other' && trim($validated['title']) === '') {
            throw ValidationException::withMessages(['title' => 'Bitte eine Bezeichnung angeben.']);
        }

        $file = $validated['proof'] ?? null;
        $proof = [];
        if ($file !== null) {
            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages(['proof' => 'Bitte eine gültige Datei auswählen.']);
            }
            $extension = Str::lower($file->getClientOriginalExtension());
            if (! in_array($extension, FileResponse::allowedExtensions(), true)) {
                throw ValidationException::withMessages(['proof' => 'Dieser Dateityp ist nicht zugelassen.']);
            }
            $path = $file->getRealPath();
            $bytes = $path !== false ? file_get_contents($path) : false;
            if ($bytes === false) {
                throw ValidationException::withMessages(['proof' => 'Die Datei konnte nicht gelesen werden.']);
            }
            $proof = [
                'proof_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                'proof_size' => strlen($bytes),
                'proof_data' => base64_encode($bytes),
            ];
        }

        return DB::transaction(function () use ($wearer, $validated, $proof, $actor) {
            Wearer::query()->whereKey($wearer->id)->lockForUpdate()->firstOrFail();

            return WearerQualification::query()->create([
                'wearer_id' => $wearer->id,
                'kind' => $validated['kind'],
                'title' => $validated['kind'] === 'other' ? trim($validated['title']) : null,
                'qualified_on' => $validated['qualified_on'] ?? null,
                'valid_until' => $validated['valid_until'] ?? null,
                'issuer' => filled($validated['issuer'] ?? null) ? trim($validated['issuer']) : null,
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
                'recorded_by' => $actor->id,
                ...$proof,
            ]);
        });
    }
}
