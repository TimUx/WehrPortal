<?php

namespace Modules\Schluessel\src\Services;

use App\Models\Place;
use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Notifications\KeyIssuedNotification;
use Modules\Schluessel\src\Notifications\KeyLostNotification;
use Modules\Schluessel\src\Notifications\KeyOverdueNotification;
use Throwable;

/**
 * Schlüsselverwaltung: the Schlüsselwart (and admins) keep the keys and hand them out with a
 * signed Schlüsselquittung; every Kamerad sees his own keys and can report one lost.
 */
class KeyService
{
    public const MODULE = 'schluessel';

    public static function today(): Carbon
    {
        return Carbon::parse(now('Europe/Berlin')->toDateString());
    }

    public static function canManage(User $user): bool
    {
        return $user->is_active && $user->canUseModule(self::MODULE)
            && ($user->is_admin || $user->hasModuleRole(self::MODULE, 'schluesselwart'));
    }

    public function managers(): EloquentCollection
    {
        $managers = User::active()->whereHas('moduleAccess', fn ($q) => $q->where('module', self::MODULE)->where('role', 'schluesselwart'))->get();

        return $managers->isNotEmpty() ? $managers : User::active()->where('is_admin', true)->get();
    }

    // ─── keys ────────────────────────────────────────────────────────────────

    public function saveKey(User $by, ?Key $key, array $data): Key
    {
        $this->assertManager($by);
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $text = fn (string $field, int $max) => filled($data[$field] ?? null) ? mb_substr(trim((string) $data[$field]), 0, $max) : null;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $fail('name', 'Bitte angeben, wofür der Schlüssel ist (höchstens 150 Zeichen).');
        }
        if (! array_key_exists($data['kind'] ?? '', Key::KINDS)) {
            $fail('kind', 'Bitte die Art wählen.');
        }
        $code = trim((string) ($data['code'] ?? ''));
        if ($code !== '' && (! preg_match('/^[A-Za-z0-9._-]{1,30}$/', $code) || Key::where('code', $code)->when($key, fn ($q) => $q->whereKeyNot($key->id))->exists())) {
            $fail('code', 'Diese Nummer ist ungültig oder schon vergeben.');
        }
        $ref = fn (string $field, string $class) => filled($data[$field] ?? null) && $class::whereKey((int) $data[$field])->exists() ? (int) $data[$field] : null;

        $key ??= new Key;
        $key->fill([
            'code' => $code !== '' ? $code : ($key->code ?? $this->nextCode()),
            'name' => $name, 'kind' => $data['kind'], 'locking' => $text('locking', 150), 'storage' => $text('storage', 150), 'notes' => $text('notes', 2000),
            'place_id' => $ref('place_id', Place::class),
            'vehicle_id' => $ref('vehicle_id', Vehicle::class),
            'object_id' => app(ModuleRegistry::class)->isActive('objektplaene') ? $ref('object_id', PlanObject::class) : $key->object_id,
        ])->save();

        return $key;
    }

    /** SL-0001, SL-0002 … */
    public function nextCode(): string
    {
        $last = Key::where('code', 'like', 'SL-%')->pluck('code')->map(fn ($c) => (int) substr($c, 3))->max() ?? 0;

        return 'SL-'.str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
    }

    // ─── handovers ───────────────────────────────────────────────────────────

    /** Hands the key to a Kamerad; with a signature, the Schlüsselquittung is signed on the screen */
    public function issue(User $by, Key $key, User $member, ?string $dueOn = null, ?string $note = null, ?string $signature = null): Handover
    {
        $this->assertManager($by);
        if ($key->status !== 'available') {
            throw ValidationException::withMessages(['issue' => 'Dieser Schlüssel ist '.mb_strtolower(Key::STATUSES[$key->status] ?? $key->status).'.']);
        }
        if (! $member->is_active) {
            throw ValidationException::withMessages(['issue' => 'Nur aktive Kameraden bekommen Schlüssel.']);
        }
        $due = filled($dueOn) ? rescue(fn () => Carbon::createFromFormat('!Y-m-d', $dueOn), null, false) : null;
        if (filled($dueOn) && (! $due || $due->lt(self::today()))) {
            throw ValidationException::withMessages(['due_on' => 'Die Rückgabe muss heute oder später sein.']);
        }

        try {
            $handover = DB::transaction(fn () => $key->handovers()->create([
                'user_id' => $member->id, 'issued_at' => now(), 'due_on' => $due, 'issued_by' => $by->id,
                'note' => filled($note) ? mb_substr(trim($note), 0, 300) : null,
                'signature' => filled($signature) ? SignatureImage::fromDataUrl($signature) : null,
            ]));
        } catch (QueryException $e) {
            // sk_handovers_one_open: somebody else just handed it out
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['issue' => 'Der Schlüssel ist schon ausgegeben.']);
            }
            throw $e;
        }

        if (! $member->is($by)) {
            $this->notify(collect([$member]), new KeyIssuedNotification($handover));
        }

        return $handover;
    }

    public function takeBack(User $by, Key $key): void
    {
        $this->assertManager($by);
        $open = $key->current ?? throw ValidationException::withMessages(['issue' => 'Der Schlüssel ist nicht ausgegeben.']);
        $open->update(['returned_at' => now(), 'returned_to' => $by->id]);
        if ($key->status === 'lost') {
            $key->update(['status' => 'available']);   // it came back after all
        }
    }

    /** Lost: by the Kamerad who has it, or by the Schlüsselwart; the other Schlüsselwarte hear about it */
    public function reportLost(User $by, Key $key, ?string $note = null): void
    {
        $holder = $key->current?->user;
        if (! self::canManage($by) && ! $holder?->is($by)) {
            throw ValidationException::withMessages(['issue' => 'Verloren melden kann nur, wer den Schlüssel hat.']);
        }
        $key->update(['status' => 'lost', 'notes' => trim(($key->notes ? $key->notes."\n" : '').'Verloren gemeldet am '.self::today()->format('d.m.Y').' von '.$by->name.(filled($note) ? ': '.trim($note) : ''))]);
        $this->notify($this->managers()->reject(fn (User $u) => $u->is($by)), new KeyLostNotification($key, $by));
    }

    /** Found again (back in the box) or retired; an open handover is closed */
    public function setStatus(User $by, Key $key, string $status): void
    {
        $this->assertManager($by);
        if (! in_array($status, ['available', 'retired'], true)) {
            throw ValidationException::withMessages(['issue' => 'Unbekannter Status.']);
        }
        DB::transaction(function () use ($by, $key, $status) {
            $key->current?->update(['returned_at' => now(), 'returned_to' => $by->id]);
            $key->update(['status' => $status]);
        });
    }

    /** Daily: a key past its return date reminds its holder once */
    public function remind(): int
    {
        $ids = collect(DB::select(
            'UPDATE sk_handovers SET reminded_at = now() WHERE returned_at IS NULL AND reminded_at IS NULL AND due_on IS NOT NULL AND due_on < ? RETURNING id',
            [self::today()->toDateString()]
        ))->pluck('id');

        Handover::with(['key', 'user'])->whereIn('id', $ids)->get()
            ->each(fn (Handover $h) => $this->notify(collect([$h->user]), new KeyOverdueNotification($h)));

        return $ids->count();
    }

    // ─── overviews ───────────────────────────────────────────────────────────

    /** Keys at Kameraden who are no longer active – to be collected */
    public function atFormerMembers(): EloquentCollection
    {
        return Handover::with(['key', 'user'])->whereNull('returned_at')->whereHas('user', fn ($q) => $q->where('status', '!=', 'active'))->get();
    }

    private function notify($users, $notification): void
    {
        $users->filter()->each(function (User $user) use ($notification) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function assertManager(User $user): void
    {
        if (! self::canManage($user)) {
            throw ValidationException::withMessages(['issue' => 'Das dürfen nur Schlüsselwarte und Admins.']);
        }
    }
}
