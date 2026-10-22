<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Kleiderkammer\src\Models\Stock;

/**
 * Explicit stock accounts only. Historical assertions never participate in live return routing.
 */
class ClothingOperations
{
    public function transfer(User $actor, int $from, int $to, int $quantity, string $reason, string $key, ?int $reverse = null): int
    {
        $reason = trim($reason);
        validator(compact('from', 'to', 'quantity', 'reason', 'key', 'reverse'), [
            'from' => ['required', 'integer', 'min:1', 'different:to'],
            'to' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason' => ['required', 'string', 'max:500'],
            'key' => ['required', 'uuid'],
            'reverse' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $from, $to, $quantity, $reason, $key, $reverse) {
            $access = $this->lockAuthority($actor, [$from, $to]);
            $stocks = Stock::withoutGlobalScopes()->whereIn('id', [$from, $to])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $stocks->get($from);
            $target = $stocks->get($to);
            abort_unless($source && $target, 403);
            $access->authorizeStock($actor, $source);
            $access->authorizeStock($actor, $target);
            $this->activeLocation($source->getAttribute('ext_location_id'));
            $this->activeLocation($target->getAttribute('ext_location_id'));
            if ($source->getAttribute('ext_location_id') === $target->getAttribute('ext_location_id')
                || $this->catalog($source->getAttributes()) !== $this->catalog($target->getAttributes())) {
                $this->invalid('Quelle und Ziel müssen dieselbe Katalogkombination an verschiedenen aktiven Standorten führen.');
            }
            if ($source->quantity < $quantity || $target->quantity < 0 || $target->quantity > 2147483647 - $quantity) {
                $this->invalid('Verfügbare Menge reicht nicht aus oder Zielmenge ist ungültig.');
            }
            if (DB::table('x_clothing_stock_transfers')->where('operation_key', $key)->exists()) {
                $this->invalid('Dieser Buchungsschlüssel wurde bereits verwendet; keine erneute Buchung.');
            }
            if ($reverse !== null) {
                $original = DB::table('x_clothing_stock_transfers')->where('id', $reverse)->lockForUpdate()->first();
                if (! $original || $original->reverses_id !== null || $original->from_stock_id !== $to
                    || $original->to_stock_id !== $from || $original->quantity !== $quantity
                    || $original->from_location_id !== $target->getAttribute('ext_location_id')
                    || $original->to_location_id !== $source->getAttribute('ext_location_id')
                    || json_decode($original->catalog, true, 512, JSON_THROW_ON_ERROR) != $this->catalog($source->getAttributes())
                    || DB::table('x_clothing_stock_transfers')->where('reverses_id', $reverse)->exists()) {
                    $this->invalid('Gegenbuchung muss eine unverändert zuordenbare Originalbuchung genau einmal vollständig umkehren.');
                }
            }
            $id = DB::table('x_clothing_stock_transfers')->insertGetId([
                'operation_key' => $key, 'from_stock_id' => $from, 'to_stock_id' => $to,
                'from_location_id' => $source->getAttribute('ext_location_id'),
                'to_location_id' => $target->getAttribute('ext_location_id'),
                'quantity' => $quantity, 'from_before' => $source->quantity, 'to_before' => $target->quantity,
                'actor_id' => $actor->id, 'reason' => $reason,
                'catalog' => json_encode($this->catalog($source->getAttributes()), JSON_THROW_ON_ERROR),
                'reverses_id' => $reverse, 'recorded_at' => now(),
            ]);
            $access->asActor($actor, function () use ($source, $target, $quantity) {
                $source->update(['quantity' => $source->quantity - $quantity]);
                $target->update(['quantity' => $target->quantity + $quantity]);
            });

            return $id;
        }, 3);
    }

    /**
     * A reviewed historical source assertion, not a source rewrite or inventory adjustment.
     * Only returned bulk issuances with a matching catalog can be reviewed here.
     */
    public function correctHistory(User $actor, int $issuance, int $source, string $reason, string $evidence, bool $apply = false, ?string $expect = null, bool $confirmed = false): array
    {
        $reason = trim($reason);
        $evidence = trim($evidence);
        validator(compact('issuance', 'source', 'reason', 'evidence'), [
            'issuance' => ['required', 'integer', 'min:1'],
            'source' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
            'evidence' => ['required', 'string', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($actor, $issuance, $source, $reason, $evidence, $apply, $expect, $confirmed) {
            $access = $this->lockAuthority($actor, [$source], globalOnly: true);
            abort_unless($access->isGlobal($actor), 403);
            $stock = DB::table('kk_stock')->where('id', $source)->lockForUpdate()->first();
            $issue = DB::table('kk_issued_items')->where('id', $issuance)->lockForUpdate()->first();
            if (! $stock || ! $issue || $issue->item_id !== null || $issue->returned_at === null
                || $issue->deleted_at !== null || $issue->quantity <= 0
                || $this->catalog((array) $issue) !== $this->catalog((array) $stock)) {
                $this->invalid('Nur zurückgegebene, nicht gelöschte Mengenausgaben mit eindeutig passender Katalogkombination sind prüfbar. Keine automatische Klärung.');
            }
            $location = $this->activeLocation($stock->ext_location_id);
            $previous = DB::table('x_clothing_history_corrections')->where('issuance_id', $issuance)->orderByDesc('id')->first();
            if (($previous->reviewed_stock_id ?? $issue->ext_source_stock_id) === $source) {
                $this->invalid('Der geprüfte historische Herkunftsverweis ist bereits unverändert hinterlegt.');
            }
            $hash = hash('sha256', json_encode([
                'version' => 1, 'actor' => (array) DB::table('users')->where('id', $actor->id)->first(),
                'issuance' => (array) $issue, 'stock' => (array) $stock, 'location' => (array) $location,
                'previous' => $previous, 'reason' => $reason, 'evidence' => $evidence,
            ], JSON_THROW_ON_ERROR));
            if ($apply && (! $confirmed || ! is_string($expect) || ! hash_equals($hash, $expect))) {
                $this->invalid('Anwenden benötigt ausdrückliche Bestätigung und den SHA256 einer unveränderten Vorschau mit identischen Prüfangaben.');
            }
            $id = null;
            if ($apply) {
                $id = DB::table('x_clothing_history_corrections')->insertGetId([
                    'issuance_id' => $issuance, 'reviewed_stock_id' => $source,
                    'reviewed_location_id' => $stock->ext_location_id, 'actor_id' => $actor->id,
                    'previous_id' => $previous?->id, 'reason' => $reason, 'evidence' => $evidence,
                    'state_hash' => $hash,
                    'original' => json_encode(array_intersect_key((array) $issue, array_flip([
                        'id', 'user_id', 'item_id', 'item_type_id', 'size_id', 'group_id', 'quantity',
                        'issued_at', 'returned_at', 'deleted_at', 'ext_source_stock_id',
                    ])), JSON_THROW_ON_ERROR),
                    'recorded_at' => now(),
                ]);
            }

            return ['hash' => $hash, 'correction_id' => $id, 'previous_id' => $previous?->id,
                'issuance_id' => $issuance, 'reviewed_stock_id' => $source, 'reviewed_location_id' => $stock->ext_location_id,
                'operational_source_unchanged' => true,
                'historical_report' => $this->historicalReport($issue)];
        }, 3);
    }

    private function lockAuthority(User $actor, array $stockIds = [], bool $globalOnly = false): ClothingStockAccess
    {
        $access = app(ClothingStockAccess::class);
        abort_unless(config('locations.enabled') && $access->enabled(), 403);
        foreach ([
            'x_clothing_stock_transfers' => ['operation_key', 'from_stock_id', 'to_stock_id', 'quantity', 'actor_id', 'reason', 'reverses_id'],
            'x_clothing_history_corrections' => ['issuance_id', 'reviewed_stock_id', 'reviewed_location_id', 'actor_id', 'previous_id', 'reason', 'evidence', 'state_hash', 'original'],
        ] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                $this->invalid('Die Migrationen der unveränderlichen Kleiderkammer-Journale müssen vollständig installiert sein.');
            }
        }
        $access->lockSelection();
        // Users precede locations, matching grant() and member removal. Shared row locks also
        // satisfy journal FK checks; table SHARE alone would allow conflicting FOR UPDATE rows.
        DB::table('users')->where('id', $actor->id)->sharedLock()->first();
        DB::table('modules')->where('key', 'kleiderkammer')->sharedLock()->first();
        $locations = DB::table('kk_stock')->whereIn('id', $stockIds)->whereNotNull('ext_location_id')->pluck('ext_location_id')->unique()->sort()->values();
        DB::table('x_locations')->whereIn('id', $locations)->orderBy('id')->sharedLock()->get();
        if (! $access->isGlobal($actor)) {
            abort_if($globalOnly, 403);
            // Freeze even absent capability rows, but never wait on unrelated user/location
            // rows while holding these table locks. Global-admin operations need no grants.
            DB::statement('LOCK TABLE x_location_user, x_location_module_roles IN SHARE MODE');
        }

        return $access;
    }

    private function activeLocation(?int $id): object
    {
        $location = $id === null ? null : DB::table('x_locations')->where('id', $id)->first();
        if (! $location || ! $location->is_active) {
            $this->invalid('Standortlose und inaktive Bestandskonten sind ausgeschlossen.');
        }

        return $location;
    }

    private function catalog(array $row): array
    {
        return ['item_type_id' => $row['item_type_id'], 'size_id' => $row['size_id'], 'group_id' => $row['group_id']];
    }

    public function history(User $actor, int $issuance): array
    {
        return DB::transaction(function () use ($actor, $issuance) {
            $access = $this->lockAuthority($actor, globalOnly: true);
            abort_unless($access->isGlobal($actor), 403);
            $issue = DB::table('kk_issued_items')->where('id', $issuance)->lockForUpdate()->first();
            abort_unless($issue !== null, 404);

            return $this->historicalReport($issue);
        }, 3);
    }

    private function historicalReport(object $issue): array
    {
        $journal = DB::table('x_clothing_history_corrections')->where('issuance_id', $issue->id)->orderBy('id')->get();
        $latest = $journal->last();

        return [
            'issuance_id' => $issue->id,
            'operational_source_stock_id' => $issue->ext_source_stock_id,
            'reviewed_source_stock_id' => $latest?->reviewed_stock_id,
            'reviewed_location_id' => $latest?->reviewed_location_id,
            'latest_correction_id' => $latest?->id,
            'review_status' => $latest === null ? 'not_reviewed' : 'documentary_source_review',
            'returned_at' => $issue->returned_at,
            'return_routing' => 'unchanged_original_source_or_legacy_rules',
            'inventory_balancing' => 'not_performed_separate_authorized_stock_counterbooking_required',
            'journal' => $journal->map(fn ($row) => (array) $row)->all(),
        ];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['operation' => $message]);
    }
}
