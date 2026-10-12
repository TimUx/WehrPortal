<?php

namespace Modules\Objektplaene\src\Services;

use App\Models\User;
use App\Services\ImageResizer;
use App\Services\ModuleRegistry;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Objektplaene\src\Models\Document;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Models\Review;
use Modules\Objektplaene\src\Models\Version;
use Modules\Objektplaene\src\Notifications\ReviewDueNotification;
use Throwable;

/**
 * Objektpläne & Brandschutzunterlagen: objects, their documents and versions, the review of the
 * plans and the suggestions for an Einsatzstelle. Every Kamerad with access reads, the
 * Planverwalter (and admins) keep the plans.
 */
class ObjectPlanService
{
    public const MODULE = 'objektplaene';

    /** Larger PDFs don't make it through the upload; plans as photos are scaled to this side */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const IMAGE_SIDE = 3000;

    /** GPS and addresses are never exact: this much outside an object still suggests it */
    public const TOLERANCE_METERS = 100;

    public function __construct(private readonly ImageResizer $images) {}

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function canView(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE);
    }

    public static function canManage(User $user): bool
    {
        return self::canView($user) && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'planverwalter'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'planverwalter'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── objects ─────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    public function saveObject(User $by, ?PlanObject $object, array $data): PlanObject
    {
        $this->assertManager($by);
        $clean = $this->validateObject($data);

        return DB::transaction(function () use ($object, $clean) {
            $object ??= new PlanObject;
            $intervalChanged = $object->review_interval_months !== $clean['review_interval_months'];
            $object->fill($clean);
            // A new interval counts from the last review, or from today for new plans
            if ($intervalChanged) {
                $object->next_review_on = $clean['review_interval_months']
                    ? ($object->last_reviewed_on ?? self::today())->copy()->addMonths($clean['review_interval_months'])
                    : null;
                $object->due_mailed_at = null;
            }
            $object->save();

            return $object;
        });
    }

    public function deleteObject(User $by, PlanObject $object): void
    {
        $this->assertManager($by);
        $object->delete();
    }

    /** "Unterlagen geprüft": the plans are still right; the next review follows the interval */
    public function review(User $by, PlanObject $object, ?string $note = null): Review
    {
        $this->assertManager($by);

        return DB::transaction(function () use ($by, $object, $note) {
            $object->update([
                'last_reviewed_on' => self::today(),
                'next_review_on' => $object->review_interval_months ? self::today()->addMonths($object->review_interval_months) : null,
                'due_mailed_at' => null,
            ]);

            return $object->reviews()->create(['reviewed_on' => self::today(), 'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null, 'user_id' => $by->id]);
        });
    }

    // ─── documents and versions ──────────────────────────────────────────────

    public function addDocument(User $by, PlanObject $object, string $kind, string $title, UploadedFile $file, ?string $note = null, ?string $validFrom = null): Document
    {
        $this->assertManager($by);
        $this->assertDocument($kind, $title);
        $upload = $this->file($file);

        return DB::transaction(function () use ($by, $object, $kind, $title, $upload, $note, $validFrom) {
            $document = $object->documents()->create([
                'kind' => $kind, 'title' => trim($title), 'sort_order' => (int) $object->documents()->max('sort_order') + 1,
            ]);
            $this->storeVersion($by, $document, 1, $upload, $note, $validFrom);

            return $document;
        });
    }

    /** A new file for the document; the earlier ones stay as history */
    public function uploadVersion(User $by, Document $document, UploadedFile $file, ?string $note = null, ?string $validFrom = null): Version
    {
        $this->assertManager($by);
        $upload = $this->file($file);

        return DB::transaction(function () use ($by, $document, $upload, $note, $validFrom) {
            // The document row is the lock: two uploads at once don't get the same number
            Document::whereKey($document->id)->lockForUpdate()->first();
            $next = (int) Version::where('document_id', $document->id)->max('version') + 1;

            return $this->storeVersion($by, $document, $next, $upload, $note, $validFrom);
        });
    }

    public function updateDocument(User $by, Document $document, string $kind, string $title): void
    {
        $this->assertManager($by);
        $this->assertDocument($kind, $title);
        $document->update(['kind' => $kind, 'title' => trim($title)]);
    }

    public function deleteDocument(User $by, Document $document): void
    {
        $this->assertManager($by);
        $document->delete();
    }

    // ─── at the Einsatz ──────────────────────────────────────────────────────

    /**
     * Objects at a point: inside their area (plus tolerance), nearest first.
     *
     * @return Collection<int, array{object: PlanObject, meters: int}>
     */
    public function near(float $lat, float $lng, int $limit = 5): Collection
    {
        return PlanObject::active()->whereNotNull('latitude')->whereNotNull('longitude')->with('documents.current')->get()
            ->map(fn (PlanObject $o) => ['object' => $o, 'meters' => (int) round(self::distance($lat, $lng, $o->latitude, $o->longitude))])
            ->filter(fn ($row) => $row['meters'] <= $row['object']->radius_m + self::TOLERANCE_METERS)
            ->sortBy('meters')->take($limit)->values();
    }

    public function forReport(Report $report): Collection
    {
        return $report->latitude !== null && $report->longitude !== null ? $this->near($report->latitude, $report->longitude) : collect();
    }

    /**
     * Running Einsätze (Einsatzbericht) with the plans of their Einsatzstelle.
     *
     * @return Collection<int, array{report: Report, objects: Collection}>
     */
    public function running(): Collection
    {
        if (! app(ModuleRegistry::class)->isActive('einsatzbericht')) {
            return collect();
        }

        return Report::where('status', 'active')->whereNull('ended_at')->whereNotNull('latitude')->whereNotNull('longitude')
            ->orderByDesc('alarmed_at')->limit(5)->get()
            ->map(fn (Report $report) => ['report' => $report, 'objects' => $this->forReport($report)])
            ->filter(fn ($row) => $row['objects']->isNotEmpty())->values();
    }

    /** Great-circle distance in metres */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371000 * asin(min(1, sqrt($a)));
    }

    /** @return list<array{id: int, lat: float, lng: float, color: string, title: string, info: string, url: string}> for the map */
    public static function markers(Collection $objects): array
    {
        return $objects->filter(fn (PlanObject $o) => $o->hasPosition())->map(fn (PlanObject $o) => [
            'id' => $o->id, 'lat' => $o->latitude, 'lng' => $o->longitude, 'color' => PlanObject::COLORS[$o->hazard_class] ?? '#6b7280',
            'title' => $o->name, 'info' => trim($o->typeLabel().' · '.$o->hazardLabel().($o->address ? ' · '.$o->address : '')),
            'url' => route('objektplaene.object', $o),
        ])->values()->all();
    }

    /** Everything a Kamerad needs without network: the pages and the current file of every plan */
    public function offlineUrls(): array
    {
        $objects = PlanObject::active()->with('documents.current')->orderBy('name')->get();

        return [
            route('objektplaene.index', absolute: false),
            ...$objects->map(fn (PlanObject $o) => route('objektplaene.object', $o, absolute: false))->all(),
            ...$objects->flatMap(fn (PlanObject $o) => $o->documents->pluck('current')->filter())->map(fn (Version $v) => route('objektplaene.file', $v, absolute: false))->all(),
        ];
    }

    // ─── reminders ───────────────────────────────────────────────────────────

    /** Daily: one mail to the Planverwalter with the objects whose review is due; each only once */
    public function remind(): int
    {
        $ids = collect(DB::select(
            'UPDATE op_objects SET due_mailed_at = now() WHERE is_active AND due_mailed_at IS NULL AND next_review_on IS NOT NULL AND next_review_on <= ? RETURNING id',
            [self::today()->toDateString()]
        ))->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $objects = PlanObject::whereIn('id', $ids)->orderBy('next_review_on')->get();
        $this->managers()->each(function (User $user) use ($objects) {
            try {
                $user->notify(new ReviewDueNotification($objects));
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $ids->count();
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** @return array{name: string, mime: string, size: int, data: string} */
    private function file(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $name = mb_substr($file->getClientOriginalName() ?: 'Plan', 0, 200);

        if ($mime === 'application/pdf') {
            if ($file->getSize() > self::MAX_BYTES) {
                throw ValidationException::withMessages(['file' => '„'.$name.'“ ist größer als 10 MB.']);
            }
            $bytes = (string) file_get_contents($file->getRealPath());
        } elseif (str_starts_with($mime, 'image/')) {
            $bytes = $this->images->toWebp($file->getRealPath(), self::IMAGE_SIDE, 'file');
            [$mime, $name] = ['image/webp', preg_replace('/\.[^.]+$/', '', $name).'.webp'];
        } else {
            throw ValidationException::withMessages(['file' => '„'.$name.'“: Bitte eine PDF-Datei oder ein Bild hochladen.']);
        }

        return ['name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'data' => base64_encode($bytes)];
    }

    private function storeVersion(User $by, Document $document, int $number, array $upload, ?string $note, ?string $validFrom): Version
    {
        $valid = filled($validFrom) ? $this->date($validFrom, 'valid_from') : null;

        $version = Version::create([...$upload, 'document_id' => $document->id, 'version' => $number,
            'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null, 'valid_from' => $valid, 'uploaded_by' => $by->id]);
        $document->touch();

        return $version;
    }

    private function assertDocument(string $kind, string $title): void
    {
        if (! array_key_exists($kind, Document::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Bitte die Art der Unterlage wählen.']);
        }
        if (trim($title) === '' || mb_strlen(trim($title)) > 150) {
            throw ValidationException::withMessages(['title' => 'Bitte einen Titel angeben (höchstens 150 Zeichen).']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function validateObject(array $data): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;
        $number = fn ($value) => is_string($value) ? str_replace(',', '.', trim($value)) : $value;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fail('name', 'Bitte den Namen des Objekts angeben (höchstens 150 Zeichen).');
        }
        if (! array_key_exists($data['object_type'] ?? '', PlanObject::TYPES)) {
            $fail('object_type', 'Bitte den Objekttyp wählen.');
        }
        if (! array_key_exists($data['hazard_class'] ?? '', PlanObject::HAZARD_CLASSES)) {
            $fail('hazard_class', 'Bitte die Gefährdungsklasse wählen.');
        }

        [$lat, $lng] = [$number($data['latitude'] ?? null), $number($data['longitude'] ?? null)];
        if (blank($lat) !== blank($lng)) {
            $fail('latitude', 'Bitte Breiten- und Längengrad angeben, oder beide leer lassen.');
        }
        if (filled($lat) && (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
            $fail('latitude', 'Die Position ist ungültig. Am einfachsten auf die Karte tippen.');
        }

        $radius = (int) ($data['radius_m'] ?? 150);
        if ($radius < 20 || $radius > 2000) {
            $fail('radius_m', 'Der Umkreis muss zwischen 20 und 2000 Metern liegen.');
        }
        $interval = filled($data['review_interval_months'] ?? null) ? (int) $data['review_interval_months'] : null;
        if ($interval !== null && ($interval < 1 || $interval > 120)) {
            $fail('review_interval_months', 'Bitte zwischen 1 und 120 Monaten.');
        }

        return [
            'name' => $name, 'object_type' => $data['object_type'], 'hazard_class' => $data['hazard_class'],
            'address' => $text('address', 200), 'latitude' => filled($lat) ? round((float) $lat, 7) : null, 'longitude' => filled($lng) ? round((float) $lng, 7) : null,
            'radius_m' => $radius, 'has_bma' => (bool) ($data['has_bma'] ?? false), 'has_fsd' => (bool) ($data['has_fsd'] ?? false),
            'has_hazardous_materials' => (bool) ($data['has_hazardous_materials'] ?? false),
            'access' => $text('access', 2000), 'hazards' => $text('hazards', 2000), 'contacts' => $text('contacts', 2000), 'notes' => $text('notes', 4000),
            'review_interval_months' => $interval, 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function date(string $value, string $field): Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Bitte ein gültiges Datum angeben.']);
        }
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['objektplaene' => 'Das dürfen nur Planverwalter und Admins.']);
        }
    }
}
