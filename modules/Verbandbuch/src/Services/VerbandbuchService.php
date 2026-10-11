<?php

namespace Modules\Verbandbuch\src\Services;

use App\Models\User;
use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Verbandbuch\src\Models\AccessLog;
use Modules\Verbandbuch\src\Models\Amendment;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Models\Signature;
use Modules\Verbandbuch\src\Notifications\EntryAboutYouNotification;
use Modules\Verbandbuch\src\Notifications\EntryRecordedNotification;
use Throwable;

/**
 * All changes of the Verbandbuch go through here:
 * 1. a Kamerad writes an entry and signs it on the screen (the injured person can sign too),
 * 2. Sicherheitsbeauftragter and Wehrführung are told – without health data in the mail,
 * 3. the entry never changes; corrections are Nachträge, chained by hash,
 * 4. they note the Unfallanzeige, cancel a wrong entry with a reason, or keep one longer,
 * 5. five years after the year it was written, the entry is deleted with all it holds.
 */
class VerbandbuchService
{
    public const MODULE = 'verbandbuch';

    public const MANAGER_ROLES = ['sicherheitsbeauftragter', 'wehrführung'];

    // ─── who may do what ─────────────────────────────────────────────────────

    /** Sicherheitsbeauftragter, Wehrführung and admins: every entry, Unfallanzeige, cancel, print the book */
    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || collect(self::MANAGER_ROLES)->contains(fn ($role) => $user->hasModuleRole(self::MODULE, $role)));
    }

    /** Health data: only the managers and whoever is part of the entry */
    public static function canView(User $user, Entry $entry): bool
    {
        if (self::canManage($user)) {
            return true;
        }

        return $user->is_active && $user->canUseModule(self::MODULE)
            && in_array($user->id, [$entry->injured_user_id, $entry->first_aider_user_id, $entry->recorded_by], true);
    }

    public static function canAmend(User $user, Entry $entry): bool
    {
        return ! $entry->isVoided() && self::canView($user, $entry);
    }

    public static function now(): Carbon
    {
        return now('Europe/Berlin');
    }

    /** Who is told about new entries; admins when nobody has a role */
    public function managers(): EloquentCollection
    {
        $managers = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->whereIn('role', self::MANAGER_ROLES))
            ->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── entries ─────────────────────────────────────────────────────────────

    /**
     * Write and sign an entry. Times are local (Europe/Berlin), as typed in the form.
     *
     * @param  array{kind: string, occasion: string, occurred_at: string, location: string, injured_user_id?: ?int, injured_name?: ?string,
     *     description: string, injury?: ?string, witnesses?: ?string, first_aid_at?: ?string, first_aid_measures?: ?string,
     *     first_aider_user_id?: ?int, first_aider_name?: ?string, doctor_visited?: bool}  $data
     * @param  string  $signature  the recorder's signature as PNG data URL
     * @param  ?string  $injuredSignature  the injured person's, if they could sign
     */
    public function create(User $by, array $data, string $signature, ?string $injuredSignature = null): Entry
    {
        $clean = $this->validate($data);
        $signatures = ['recorder' => SignatureImage::fromDataUrl($signature, 'signature')];
        if (filled($injuredSignature)) {
            if ($clean['injured_name'] === null) {
                throw ValidationException::withMessages(['injured_signature' => 'Ohne Namen der verletzten Person kann sie nicht unterschreiben.']);
            }
            $signatures['injured'] = SignatureImage::fromDataUrl($injuredSignature, 'injured_signature');
        }

        $entry = DB::transaction(function () use ($by, $clean, $signatures) {
            $year = $clean['occurred_at']->copy()->setTimezone('Europe/Berlin')->year;
            DB::select('select pg_advisory_xact_lock(?)', [crc32('vb_entries_'.$year)]);
            $signedAt = now()->startOfSecond();

            $rows = collect($signatures)->map(fn (string $data, string $role) => [
                'role' => $role,
                'name' => $role === 'recorder' ? $by->name : $clean['injured_name'],
                'user_id' => $role === 'recorder' ? $by->id : $clean['injured_user_id'],
                'data' => $data,
                'signed_at' => $signedAt,
            ])->values();

            $entry = new Entry([
                ...$clean,
                'year' => $year,
                'number' => (int) Entry::where('year', $year)->max('number') + 1,
                'recorded_by' => $by->id,
                'recorded_by_name' => $by->name,
                'retain_until' => self::now()->addYears(Entry::RETENTION_YEARS)->endOfYear()->toDateString(),
            ]);
            $entry->content_hash = $this->hash($entry, $rows);
            $entry->save();
            $entry->signatures()->createMany($rows->all());
            $this->log($entry, $by, 'created');

            return $entry;
        });

        $this->notify($this->managers()->reject(fn (User $u) => $u->is($by)), new EntryRecordedNotification($entry));
        if ($entry->injured_user_id && $entry->injured_user_id !== $by->id && ! $this->managers()->contains('id', $entry->injured_user_id)) {
            $this->notify(collect([$entry->injuredUser]), new EntryAboutYouNotification($entry));
        }

        return $entry;
    }

    /** A Nachtrag: adds to or corrects the entry, the entry itself stays as signed */
    public function amend(User $by, Entry $entry, string $text): Amendment
    {
        if (! self::canAmend($by, $entry)) {
            throw ValidationException::withMessages(['amendment' => 'Du kannst zu diesem Eintrag keinen Nachtrag schreiben.']);
        }
        $text = trim($text);
        if (mb_strlen($text) < 3 || mb_strlen($text) > 2000) {
            throw ValidationException::withMessages(['amendment' => 'Bitte einen Nachtrag mit 3 bis 2000 Zeichen schreiben.']);
        }

        return DB::transaction(function () use ($by, $entry, $text) {
            $entry = Entry::lockForUpdate()->findOrFail($entry->id);
            $previous = $entry->amendments()->latest('id')->value('hash') ?? $entry->content_hash;
            $createdAt = now()->startOfSecond();

            $amendment = $entry->amendments()->create([
                'user_id' => $by->id, 'user_name' => $by->name, 'text' => $text,
                'hash' => $this->amendmentHash($previous, $by->name, $text, $createdAt),
                'created_at' => $createdAt,
            ]);
            $this->log($entry, $by, 'amended');

            return $amendment;
        });
    }

    /** The Unfallanzeige to the Unfallkasse went out (or isn't needed): note when and how */
    public function markReported(User $by, Entry $entry, string $date, ?string $note): void
    {
        $this->assertManager($by);
        $day = $this->parseDate($date, 'reported_at');
        if ($day->isAfter(self::now()->endOfDay())) {
            throw ValidationException::withMessages(['reported_at' => 'Das Datum liegt in der Zukunft.']);
        }

        $entry->update(['reported_at' => $day->setTime(12, 0)->utc(), 'report_note' => $this->text($note, 300), 'reported_by' => $by->id]);
        $this->log($entry, $by, 'reported');
    }

    /** A wrong entry is cancelled, never deleted: it stays readable with the reason */
    public function void(User $by, Entry $entry, string $reason): void
    {
        $this->assertManager($by);
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['void_reason' => 'Bitte den Grund für die Stornierung angeben.']);
        }
        if ($entry->isVoided()) {
            return;
        }

        $entry->update(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 500), 'voided_by' => $by->id]);
        $this->log($entry, $by, 'voided');
    }

    /** Keep an entry beyond the retention time (e.g. while the Unfallkasse still has questions) */
    public function setHold(User $by, Entry $entry, bool $hold): void
    {
        $this->assertManager($by);
        if ($entry->hold === $hold) {
            return;
        }

        $entry->update(['hold' => $hold]);
        $this->log($entry, $by, $hold ? 'hold' : 'released');
    }

    /** Whether content, signatures and Nachträge are still exactly what was signed */
    public function verify(Entry $entry): bool
    {
        $signatures = $entry->signatures()->get()->map(fn (Signature $s) => [
            'role' => $s->role, 'name' => $s->name, 'data' => $s->data, 'signed_at' => $s->signed_at,
        ]);

        if (! hash_equals($entry->content_hash, $this->hash($entry, $signatures))) {
            return false;
        }

        $previous = $entry->content_hash;
        foreach ($entry->amendments()->get() as $amendment) {
            if (! hash_equals($amendment->hash, $this->amendmentHash($previous, $amendment->user_name, $amendment->text, $amendment->created_at))) {
                return false;
            }
            $previous = $amendment->hash;
        }

        return true;
    }

    public function log(Entry $entry, ?User $user, string $action): void
    {
        try {
            AccessLog::create(['entry_id' => $entry->id, 'user_id' => $user?->id, 'action' => $action]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ─── details ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed> the entry's fields, checked; times as UTC Carbon */
    private function validate(array $data): array
    {
        $errors = [];
        $kind = (string) ($data['kind'] ?? '');
        $hurt = in_array($kind, ['injury', 'accident'], true);

        if (! array_key_exists($kind, Entry::KINDS)) {
            $errors['kind'] = 'Bitte die Art des Eintrags wählen.';
        }
        if (! array_key_exists((string) ($data['occasion'] ?? ''), Entry::OCCASIONS)) {
            $errors['occasion'] = 'Bitte angeben, wobei es passiert ist.';
        }

        $occurred = $this->parseTime($data['occurred_at'] ?? null);
        if (! $occurred) {
            $errors['occurred_at'] = 'Bitte Datum und Uhrzeit angeben.';
        } elseif ($occurred->isAfter(now()->addMinutes(5))) {
            $errors['occurred_at'] = 'Der Zeitpunkt liegt in der Zukunft.';
        } elseif ($occurred->isBefore(now()->subYears(Entry::RETENTION_YEARS))) {
            $errors['occurred_at'] = 'Der Zeitpunkt liegt mehr als '.Entry::RETENTION_YEARS.' Jahre zurück.';
        }

        $location = $this->text($data['location'] ?? null, 150);
        if ($location === null) {
            $errors['location'] = 'Bitte den Ort angeben (z. B. Gerätehaus, Einsatzstelle).';
        }

        [$injuredId, $injuredName] = $this->person($data['injured_user_id'] ?? null, $data['injured_name'] ?? null);
        if ($hurt && $injuredName === null) {
            $errors['injured_name'] = 'Bitte angeben, wer verletzt wurde.';
        }

        $description = $this->text($data['description'] ?? null, 5000);
        if ($description === null) {
            $errors['description'] = 'Bitte beschreiben, was passiert ist.';
        }

        $injury = $this->text($data['injury'] ?? null, 2000);
        if ($hurt && $injury === null) {
            $errors['injury'] = 'Bitte Art und Umfang der Verletzung angeben.';
        }

        $measures = $this->text($data['first_aid_measures'] ?? null, 2000);
        if ($kind === 'injury' && $measures === null) {
            $errors['first_aid_measures'] = 'Bitte die Erste-Hilfe-Maßnahmen angeben (z. B. Pflaster, Kühlung).';
        }

        [$aiderId, $aiderName] = $this->person($data['first_aider_user_id'] ?? null, $data['first_aider_name'] ?? null);
        if ($measures !== null && $aiderName === null) {
            $errors['first_aider_name'] = 'Bitte angeben, wer Erste Hilfe geleistet hat.';
        }

        $firstAidAt = null;
        if (filled($data['first_aid_at'] ?? null)) {
            $firstAidAt = $this->parseTime($data['first_aid_at']);
            if (! $firstAidAt) {
                $errors['first_aid_at'] = 'Bitte Datum und Uhrzeit der Ersten Hilfe angeben.';
            } elseif ($occurred && $firstAidAt->isBefore($occurred)) {
                $errors['first_aid_at'] = 'Die Erste Hilfe kann nicht vor dem Ereignis liegen.';
            } elseif ($firstAidAt->isAfter(now()->addMinutes(5))) {
                $errors['first_aid_at'] = 'Der Zeitpunkt liegt in der Zukunft.';
            }
        } elseif ($measures !== null) {
            $firstAidAt = $occurred;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'kind' => $kind, 'occasion' => $data['occasion'], 'occurred_at' => $occurred, 'location' => $location,
            'injured_user_id' => $injuredId, 'injured_name' => $injuredName, 'description' => $description,
            'injury' => $kind === 'incident' ? null : $injury, 'witnesses' => $this->text($data['witnesses'] ?? null, 2000),
            'first_aid_at' => $measures !== null ? $firstAidAt : null, 'first_aid_measures' => $measures,
            'first_aider_user_id' => $measures !== null ? $aiderId : null, 'first_aider_name' => $measures !== null ? $aiderName : null,
            'doctor_visited' => $kind !== 'incident' && (bool) ($data['doctor_visited'] ?? false),
        ];
    }

    /** @return array{0: ?int, 1: ?string} a Kamerad (his name as of now) or a typed name, e.g. a guest */
    private function person(mixed $userId, mixed $name): array
    {
        if (filled($userId) && $user = User::find((int) $userId)) {
            return [$user->id, $user->name];
        }

        return [null, $this->text($name, 150)];
    }

    /** SHA-256 over everything that was signed */
    private function hash(Entry $entry, Collection $signatures): string
    {
        $time = fn ($t) => $t ? Carbon::parse($t)->utc()->format('Y-m-d H:i:s') : null;

        return hash('sha256', json_encode([
            'number' => $entry->year.'-'.$entry->number,
            'kind' => $entry->kind, 'occasion' => $entry->occasion, 'occurred_at' => $time($entry->occurred_at), 'location' => $entry->location,
            'injured' => $entry->injured_name, 'description' => $entry->description, 'injury' => $entry->injury, 'witnesses' => $entry->witnesses,
            'first_aid_at' => $time($entry->first_aid_at), 'first_aid_measures' => $entry->first_aid_measures, 'first_aider' => $entry->first_aider_name,
            'doctor_visited' => (bool) $entry->doctor_visited, 'recorded_by' => $entry->recorded_by_name,
            'signatures' => $signatures->sortBy('role')->map(fn ($s) => [
                'role' => $s['role'], 'name' => $s['name'], 'signed_at' => $time($s['signed_at']), 'png' => hash('sha256', $s['data']),
            ])->values()->all(),
        ], JSON_UNESCAPED_UNICODE));
    }

    private function amendmentHash(string $previous, string $name, string $text, $createdAt): string
    {
        return hash('sha256', $previous."\n".$name."\n".Carbon::parse($createdAt)->utc()->format('Y-m-d H:i:s')."\n".$text);
    }

    /** "2027-05-10T14:30" from the form, local time → UTC */
    private function parseTime(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->utc()->startOfMinute();
        }

        try {
            return filled($value) ? Carbon::parse((string) $value, 'Europe/Berlin')->utc()->startOfMinute() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(string $value, string $field): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $value, 'Europe/Berlin');
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Bitte ein gültiges Datum angeben.']);
        }
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['entry' => 'Das dürfen nur Sicherheitsbeauftragte, Wehrführung und Admins.']);
        }
    }

    private function notify(Collection $users, Notification $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
