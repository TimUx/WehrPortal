<?php

namespace Modules\Einsatzbericht\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use App\Support\QrCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\ExternalUnit;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationMessage;
use Throwable;

/**
 * QR access for external Wehren at large Einsätze. The Einsatzleiter makes a code for one Einsatz;
 * whoever scans it registers once (Wehr, vehicle, Stärke) and then sends status and Lagemeldungen
 * without login. The code works only while the Einsatz runs and can be blocked at once. External
 * phones see only their own entries – no personnel and no report data.
 */
class ExternalAccess
{
    public const MAX_MESSAGES = 30;

    public const MAX_STATUSES = 60;

    /** Per phone and minute, for status and messages together */
    public const PER_MINUTE = 10;

    private const MAX_SIDE = 1600;

    public function __construct(private readonly ImageResizer $images) {}

    /** A new code; an older one stops working */
    public function enable(User $by, Report $report): string
    {
        $this->assertLead($by, $report);

        if (! $report->isRunning()) {
            throw ValidationException::withMessages(['external' => 'Einen QR-Zugang gibt es nur für einen laufenden Einsatz (ohne Einsatzende).']);
        }

        $key = Str::random(40);
        $report->update(['external_key' => Crypt::encryptString($key), 'external_key_hash' => hash('sha256', $key)]);

        return $key;
    }

    /** Blocks the code at once; registered Wehren keep their entries */
    public function revoke(User $by, Report $report): void
    {
        $this->assertLead($by, $report);
        $report->update(['external_key' => null, 'external_key_hash' => null]);
    }

    public static function key(Report $report): ?string
    {
        try {
            return $report->external_key ? Crypt::decryptString($report->external_key) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function url(Report $report): ?string
    {
        return ($key = self::key($report)) ? route('einsatzbericht.external', ['key' => $key]) : null;
    }

    /** The QR code as SVG data URI; the phone camera opens the address directly */
    public static function qr(Report $report, int $size = 320): ?string
    {
        $url = self::url($report);

        return $url ? QrCode::dataUri($url, $size) : null;
    }

    /** The Einsatz of a code, if the code is valid and the Einsatz still runs */
    public static function find(string $key): ?Report
    {
        $report = Report::where('external_key_hash', hash('sha256', $key))->first();

        return $report && $report->isRunning() ? $report : null;
    }

    public static function unitFor(Report $report, ?string $deviceToken): ?ExternalUnit
    {
        return $deviceToken ? $report->externalUnits()->where('device_hash', hash('sha256', $deviceToken))->first() : null;
    }

    /** Once per phone: Wehr, vehicle and Stärke */
    public function register(Report $report, string $deviceToken, string $name, ?string $vehicle, ?string $strength): ExternalUnit
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Bitte den Namen der Wehr angeben.']);
        }

        if ($strength !== null && trim($strength) !== '' && ! preg_match('#^\d{1,3}(/\d{1,3}){0,3}$#', trim($strength))) {
            throw ValidationException::withMessages(['strength' => 'Stärke bitte als Zahl oder z. B. 1/8/9 angeben.']);
        }

        if ($report->externalUnits()->count() >= 50) {
            throw ValidationException::withMessages(['name' => 'Für diesen Einsatz sind schon zu viele Wehren angemeldet.']);
        }

        return ExternalUnit::updateOrCreate(
            ['report_id' => $report->id, 'device_hash' => hash('sha256', $deviceToken)],
            ['name' => mb_substr($name, 0, 150), 'vehicle' => filled($vehicle) ? mb_substr(trim($vehicle), 0, 100) : null, 'strength' => filled($strength) ? trim($strength) : null],
        );
    }

    public function status(ExternalUnit $unit, string $status): void
    {
        if (! array_key_exists($status, ExternalUnit::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unbekannter Status.']);
        }

        $this->limit($unit, $unit->statuses()->count() >= self::MAX_STATUSES);
        $unit->statuses()->create(['status' => $status, 'created_at' => now()]);
    }

    /** @param  list<UploadedFile>  $photos */
    public function message(ExternalUnit $unit, ?string $text, array $photos = []): SituationMessage
    {
        $text = trim((string) $text);
        if ($text === '' && $photos === []) {
            throw ValidationException::withMessages(['message' => 'Bitte einen Text oder ein Foto angeben.']);
        }

        if (count($photos) > SituationService::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'Höchstens '.SituationService::MAX_PHOTOS.' Fotos je Meldung.']);
        }

        $this->limit($unit, $unit->messages()->count() >= self::MAX_MESSAGES);
        $scaled = array_map(fn (UploadedFile $photo) => $this->images->toWebp($photo->getRealPath(), self::MAX_SIDE), $photos);

        return DB::transaction(function () use ($unit, $text, $scaled) {
            $message = SituationMessage::create(['report_id' => $unit->report_id, 'external_unit_id' => $unit->id, 'text' => $text !== '' ? mb_substr($text, 0, 2000) : null]);
            foreach ($scaled as $bytes) {
                $message->photos()->create(['mime' => 'image/webp', 'data' => base64_encode($bytes)]);
            }

            return $message;
        });
    }

    private function limit(ExternalUnit $unit, bool $tooMany): void
    {
        if ($tooMany) {
            throw ValidationException::withMessages(['message' => 'Die Höchstzahl an Meldungen für dieses Gerät ist erreicht. Bitte direkt an die Einsatzleitung wenden.']);
        }

        $key = 'eb-external:'.$unit->id;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw ValidationException::withMessages(['message' => 'Zu viele Meldungen in kurzer Zeit – bitte einen Moment warten.']);
        }
        RateLimiter::hit($key, 60);
    }

    private function assertLead(User $by, Report $report): void
    {
        if (! ReportService::canLeadReport($by, $report)) {
            throw ValidationException::withMessages(['external' => 'Den QR-Zugang erzeugen und sperren Einsatzleiter und Wehrführung.']);
        }
    }
}
