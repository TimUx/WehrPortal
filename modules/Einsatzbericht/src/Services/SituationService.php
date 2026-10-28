<?php

namespace Modules\Einsatzbericht\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationMessage;

/**
 * Lagemeldungen: every Kamerad with the module writes them while the Einsatz is running (a draft
 * without Einsatzende); Einsatzleiter and Wehrführung always. They stay on the report as the
 * Einsatztagebuch. Photos are scaled down on the server.
 */
class SituationService
{
    public const MAX_PHOTOS = 3;

    public const MAX_PHOTO_KB = 15360;

    private const MAX_SIDE = 1600;

    public function __construct(private readonly ImageResizer $images) {}

    public static function canSee(User $user, Report $report): bool
    {
        if (ReportService::canLeadReport($user, $report)) {
            return true;
        }

        if ($user->hasAnyModuleRole('einsatzbericht', 'wehrführung')
            && ! $user->hasModuleRole('einsatzbericht', 'wehrführung')
            && ! $user->hasModuleRole('einsatzbericht', 'einsatzleiter')) {
            return false;
        }

        return $user->is_active && $user->canUseModule('einsatzbericht') && $report->isRunning();
    }

    /** @param  list<UploadedFile>  $photos */
    public function post(User $by, Report $report, ?string $text, bool $important, array $photos = []): SituationMessage
    {
        if (! self::canSee($by, $report)) {
            throw ValidationException::withMessages(['message' => 'Der Einsatz ist beendet; Lagemeldungen schreiben nur noch Einsatzleiter und Wehrführung.']);
        }

        $text = trim((string) $text);
        if ($text === '' && $photos === []) {
            throw ValidationException::withMessages(['message' => 'Bitte einen Text oder ein Foto angeben.']);
        }

        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'Höchstens '.self::MAX_PHOTOS.' Fotos je Meldung.']);
        }

        // Scale first, so a broken photo saves nothing
        $scaled = array_map(fn (UploadedFile $photo) => $this->images->toWebp($photo->getRealPath(), self::MAX_SIDE), $photos);

        return DB::transaction(function () use ($by, $report, $text, $important, $scaled) {
            $message = $report->messages()->create(['user_id' => $by->id, 'text' => $text !== '' ? mb_substr($text, 0, 2000) : null, 'important' => $important]);
            foreach ($scaled as $bytes) {
                $message->photos()->create(['mime' => 'image/webp', 'data' => base64_encode($bytes)]);
            }

            return $message;
        });
    }

    /** Einsatzleiter and Wehrführung mark or unmark a message as important */
    public function toggleImportant(User $by, SituationMessage $message): void
    {
        if (! ReportService::canLeadReport($by, $message->report)) {
            throw ValidationException::withMessages(['message' => 'Wichtig markieren Einsatzleiter und Wehrführung.']);
        }

        $message->update(['important' => ! $message->important]);
    }

    /** The author while the Einsatz runs, Einsatzleiter and Wehrführung always */
    public function delete(User $by, SituationMessage $message): void
    {
        $own = $message->user_id === $by->id && $message->report->isRunning();
        if (! $own && ! ReportService::canLeadReport($by, $message->report)) {
            throw ValidationException::withMessages(['message' => 'Diese Meldung kannst du nicht löschen.']);
        }

        $message->delete();
    }
}
