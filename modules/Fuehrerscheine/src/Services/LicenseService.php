<?php

namespace Modules\Fuehrerscheine\src\Services;

use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fuehrerscheine\src\Models\Check;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Models\Photo;
use Modules\Fuehrerscheine\src\Notifications\LicenseDecidedNotification;
use Modules\Fuehrerscheine\src\Notifications\LicenseReminderNotification;
use Modules\Fuehrerscheine\src\Notifications\LicenseSubmittedNotification;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;
use Throwable;

/**
 * Führerscheinkontrolle without a date at the Wache: the Kamerad enters the classes and sends photos of the
 * card, the Führerscheinprüfer compares and validates. The photos are deleted with the decision; the check
 * is repeated after an interval. Who may drive which vehicle follows from the classes and the vehicle.
 */
class LicenseService
{
    public const MODULE = 'fuehrerscheine';

    public const INTERVAL_KEY = 'fuehrerscheine.interval_months';

    /** Reminders this many days before a class or the card runs out */
    public const WARN_DAYS = 60;

    /** What satisfies a required class (holding C implies C1 and B …) */
    private const SATISFIES = [
        'B' => ['B', 'BE', 'C1', 'C1E', 'C', 'CE', 'D1', 'D1E', 'D', 'DE'], 'BE' => ['BE', 'C1E', 'CE', 'D1E', 'DE'],
        'C1' => ['C1', 'C1E', 'C', 'CE'], 'C1E' => ['C1E', 'CE'], 'C' => ['C', 'CE'], 'CE' => ['CE'],
        'D1' => ['D1', 'D1E', 'D', 'DE'], 'D1E' => ['D1E', 'DE'], 'D' => ['D', 'DE'], 'DE' => ['DE'],
    ];

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'fuehrerscheinpruefer'));
    }

    public static function interval(): int
    {
        return max(1, min(36, (int) Setting::get(self::INTERVAL_KEY, 6)));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'fuehrerscheinpruefer'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    /**
     * The Kamerad enters the card and sends it for checking with photos of front and back. Any change of the
     * classes needs a new check; the old photos are replaced.
     *
     * @param  list<array{class: string, valid_until?: ?string}>  $classes
     */
    public function submit(User $member, ?string $documentValidUntil, array $classes, ?UploadedFile $front, ?UploadedFile $back): License
    {
        $clean = $this->validated($documentValidUntil, $classes);
        $license = License::firstOrNew(['user_id' => $member->id]);
        if (! $front && ! $license->exists) {
            throw ValidationException::withMessages(['front' => 'Bitte ein Foto der Vorderseite hochladen.']);
        }
        if (! $back && ! $license->exists) {
            throw ValidationException::withMessages(['back' => 'Bitte ein Foto der Rückseite (Klassen) hochladen.']);
        }
        $hasPhotos = $license->exists && $license->photos()->count() === 2;
        if ((! $front || ! $back) && ! $hasPhotos) {
            throw ValidationException::withMessages(['front' => 'Bitte Vorder- und Rückseite fotografieren – zum Prüfen braucht es beide.']);
        }

        DB::transaction(function () use ($license, $clean, $front, $back) {
            $license->fill([...$clean, 'status' => 'pending', 'submitted_at' => now(), 'rejected_reason' => null])->save();
            foreach (['front' => $front, 'back' => $back] as $side => $file) {
                if ($file) {
                    Photo::updateOrCreate(['license_id' => $license->id, 'side' => $side], ['mime' => $file->getMimeType(), 'data' => base64_encode((string) file_get_contents($file->getRealPath()))]);
                }
            }
        });
        $this->notify($this->managers()->reject(fn (User $u) => $u->is($member)), new LicenseSubmittedNotification($license->load('user')));

        return $license;
    }

    /** The Prüfer confirms: the photos match. A card that has run out can't be validated, a class that has run out can */
    public function validate(User $by, License $license, ?string $note = null): void
    {
        $this->assertManager($by);
        if ($license->status !== 'pending') {
            throw ValidationException::withMessages(['license' => 'Dieser Führerschein wartet nicht auf eine Prüfung.']);
        }
        if ($license->documentExpired()) {
            throw ValidationException::withMessages(['license' => 'Der Führerschein selbst ist abgelaufen – so lässt er sich nicht bestätigen.']);
        }
        $this->decide($by, $license, 'validated', $note);
    }

    public function reject(User $by, License $license, string $reason): void
    {
        $this->assertManager($by);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Bitte kurz sagen, was nicht passt – der Kamerad bekommt es.']);
        }
        $this->decide($by, $license, 'rejected', $reason);
    }

    private function decide(User $by, License $license, string $result, ?string $note): void
    {
        DB::transaction(function () use ($by, $license, $result, $note) {
            Check::create(['license_id' => $license->id, 'result' => $result, 'checked_by' => $by->id, 'created_at' => now(),
                'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null,
                'snapshot' => ['document_valid_until' => $license->document_valid_until?->toDateString(), 'classes' => $license->classes]]);
            $license->update($result === 'validated'
                ? ['status' => 'validated', 'validated_at' => now(), 'validated_by' => $by->id, 'next_check_on' => self::today()->addMonths(self::interval()), 'reminded' => null]
                : ['status' => 'rejected', 'rejected_reason' => mb_substr(trim((string) $note), 0, 500)]);
            // Data minimisation: the photos have done their job
            Photo::where('license_id', $license->id)->delete();
        });
        $this->syncCalendar($license->fresh());
        $this->notify(collect([$license->user]), new LicenseDecidedNotification($license->fresh()));
    }

    // ─── who may drive what ──────────────────────────────────────────────────

    /** @return array{label: string, accept: list<string>}|null what the vehicle needs, from its Führerscheinklasse or its weight (Fahrzeuge) */
    public static function requirement(int $vehicleId): ?array
    {
        if (! app(ModuleRegistry::class)->isActive('fahrzeuge')) {
            return null;
        }
        $profile = DB::table('fv_profiles')->where('vehicle_id', $vehicleId)->first(['license_class', 'gross_weight_kg']);
        $weight = $profile?->gross_weight_kg ? (int) $profile->gross_weight_kg : null;
        $text = mb_strtoupper((string) $profile?->license_class);
        $token = collect(['C1E', 'D1E', 'CE', 'DE', 'BE', 'C1', 'D1', 'C', 'D', 'B'])->first(fn ($c) => preg_match('/(^|[^A-Z0-9])'.$c.'($|[^A-Z0-9])/', $text));

        if ($token) {
            $fire = $token === 'C1' ? ['FW75', ...($weight && $weight <= 4750 ? ['FW475'] : [])] : [];

            return ['label' => $token.($fire ? ' oder Feuerwehr-Fahrberechtigung' : ''), 'accept' => [...self::SATISFIES[$token], ...$fire]];
        }

        return match (true) {
            $weight === null => null,
            $weight <= 3500 => ['label' => 'B', 'accept' => self::SATISFIES['B']],
            $weight <= 4750 => ['label' => 'C1 oder Feuerwehr-Fahrberechtigung bis 4,75 t', 'accept' => [...self::SATISFIES['C1'], 'FW475', 'FW75']],
            $weight <= 7500 => ['label' => 'C1 oder Feuerwehr-Fahrberechtigung bis 7,5 t', 'accept' => [...self::SATISFIES['C1'], 'FW75']],
            default => ['label' => 'C', 'accept' => self::SATISFIES['C']],
        };
    }

    public static function mayDrive(?License $license, array $requirement): bool
    {
        return $license !== null && $license->usable() && array_intersect($license->validClasses(), $requirement['accept']) !== [];
    }

    /** A hint for the Fahrtenbuch when the driver lacks a valid, checked Fahrerlaubnis for the vehicle */
    public function warning(User $driver, int $vehicleId): ?string
    {
        $requirement = self::requirement($vehicleId);
        if (! $requirement) {
            return null;
        }
        $license = License::where('user_id', $driver->id)->first();
        $vehicle = Vehicle::find($vehicleId)?->name ?? 'das Fahrzeug';

        return match (true) {
            $license === null => 'Für '.$driver->name.' ist kein Führerschein hinterlegt – für '.$vehicle.' braucht es '.$requirement['label'].'.',
            $license->documentExpired() => 'Der Führerschein von '.$driver->name.' ist abgelaufen.',
            $license->status !== 'validated' => 'Der Führerschein von '.$driver->name.' ist noch nicht geprüft.',
            ! self::mayDrive($license, $requirement) => $driver->name.' hat keine gültige Fahrerlaubnis für '.$vehicle.' ('.$requirement['label'].').',
            $license->checkDue() => 'Die Führerscheinprüfung von '.$driver->name.' ist fällig.',
            default => null,
        };
    }

    /** @return array{required: string, users: Collection}|null */
    public function drivers(int $vehicleId): ?array
    {
        $requirement = self::requirement($vehicleId);
        if (! $requirement) {
            return null;
        }

        return ['required' => $requirement['label'], 'users' => License::with('user')->where('status', 'validated')->get()
            ->filter(fn (License $l) => $l->user?->is_active && self::mayDrive($l, $requirement))->pluck('user')->sortBy('name')->values()];
    }

    // ─── Auswertung, Erinnerungen, Kalender ──────────────────────────────────

    /** @return array<string, array<string, int>> Abteilung → class → number of Kameraden with it valid and checked */
    public function matrix(): array
    {
        $matrix = [];
        foreach (License::with('user.groups')->where('status', 'validated')->get() as $license) {
            if (! $license->user?->is_active || $license->documentExpired()) {
                continue;
            }
            foreach ($license->user->groups->pluck('name')->whenEmpty(fn ($c) => $c->push('ohne Abteilung')) as $group) {
                foreach ($license->validClasses() as $class) {
                    $matrix[$group][$class] = ($matrix[$group][$class] ?? 0) + 1;
                }
            }
        }
        ksort($matrix);

        return $matrix;
    }

    /** Daily: a class or the card runs out in WARN_DAYS, or the check is due – the Kamerad is told once per date */
    public function remind(): int
    {
        if (! app(ModuleRegistry::class)->isActive(self::MODULE)) {
            return 0;
        }
        $limit = self::today()->addDays(self::WARN_DAYS);
        $sent = 0;
        foreach (License::with('user')->whereIn('status', ['validated', 'rejected'])->get() as $license) {
            $due = collect($license->classes ?? [])->filter(fn ($c) => filled($c['valid_until'] ?? null) && Carbon::parse($c['valid_until'])->lte($limit))
                ->mapWithKeys(fn ($c) => ['class.'.$c['class'] => [License::label($c['class']).' läuft ab am '.Carbon::parse($c['valid_until'])->format('d.m.Y'), $c['valid_until']]]);
            if ($license->document_valid_until?->lte($limit)) {
                $due['document'] = ['Der Führerschein (Karte) läuft ab am '.$license->document_valid_until->format('d.m.Y'), $license->document_valid_until->toDateString()];
            }
            if ($license->checkDue()) {
                $due['check'] = ['Die Führerscheinprüfung ist fällig – bitte neue Fotos hochladen', $license->next_check_on->toDateString()];
            }
            $reminded = $license->reminded ?? [];
            $new = $due->filter(fn ($item, $key) => ($reminded[$key] ?? null) !== $item[1]);
            if ($new->isEmpty() || ! $license->user?->is_active) {
                continue;
            }
            $license->update(['reminded' => [...$reminded, ...$new->map(fn ($i) => $i[1])->all()]]);
            $this->notify(collect([$license->user]), new LicenseReminderNotification($new->map(fn ($i) => $i[0])->values()->all()));
            $sent++;
        }

        return $sent;
    }

    /** The card and the classes that run out are in the Kamerad's own Kalender (only for them) */
    public function syncCalendar(License $license): void
    {
        if (! app(ModuleRegistry::class)->isActive('kalender') || ! $license->user) {
            return;
        }
        try {
            Event::where('managed_by', self::MODULE)->whereHas('invitees', fn ($q) => $q->where('users.id', $license->user_id))->delete();
            if ($license->status !== 'validated') {
                return;
            }
            $dates = collect($license->classes ?? [])->filter(fn ($c) => filled($c['valid_until'] ?? null))
                ->map(fn ($c) => [License::label($c['class']).' läuft ab', Carbon::parse($c['valid_until'])])
                ->when($license->document_valid_until, fn ($c) => $c->push(['Führerschein (Karte) läuft ab', $license->document_valid_until]))
                ->when($license->next_check_on, fn ($c) => $c->push(['Führerscheinprüfung fällig', $license->next_check_on]))
                ->filter(fn ($d) => $d[1]->gte(self::today()));
            foreach ($dates as [$title, $day]) {
                $start = Carbon::createFromFormat('!Y-m-d', $day->toDateString(), 'Europe/Berlin');
                app(EventService::class)->create(['title' => $title, 'type' => 'sonstiges', 'all_day' => true, 'starts_at' => $start, 'ends_at' => $start->copy()->addDay(),
                    'managed_by' => self::MODULE, 'description' => 'Aus den Führerscheinen – bitte rechtzeitig verlängern bzw. neue Fotos hochladen.'], [], null, null, false, [$license->user_id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array{document_valid_until: ?Carbon, classes: list<array{class: string, valid_until: ?string}>} */
    private function validated(?string $documentValidUntil, array $classes): array
    {
        $date = fn (?string $d) => filled($d) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', $d), null, false) : null;
        $document = $date($documentValidUntil);
        if (filled($documentValidUntil) && ! $document) {
            throw ValidationException::withMessages(['document' => 'Bitte ein gültiges Datum angeben.']);
        }
        $allowed = [...License::CLASSES, ...array_keys(License::FIRE)];
        $clean = collect($classes)->filter(fn ($c) => in_array($c['class'] ?? null, $allowed, true))
            ->unique('class')->map(fn ($c) => ['class' => $c['class'], 'valid_until' => $date($c['valid_until'] ?? null)?->toDateString()])->values()->all();
        if ($clean === []) {
            throw ValidationException::withMessages(['classes' => 'Bitte mindestens eine Klasse ankreuzen.']);
        }
        if (collect($clean)->contains(fn ($c) => in_array($c['class'], License::LIMITED, true) && ! $c['valid_until'])) {
            throw ValidationException::withMessages(['classes' => 'Klassen C1, C, D … gelten fünf Jahre – bitte das Ablaufdatum von der Rückseite (Spalte 11) eintragen.']);
        }

        return ['document_valid_until' => $document, 'classes' => $clean];
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['license' => 'Das dürfen nur Führerscheinprüfer und Admins.']);
        }
    }

    private function notify(Collection $users, $notification): void
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
