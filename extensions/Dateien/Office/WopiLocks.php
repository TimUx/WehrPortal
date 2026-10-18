<?php

namespace Extensions\Dateien\Office;

use Extensions\Dateien\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * WOPI locks, one per file, 30 minutes (the office server refreshes them). Every operation locks
 * the file row first, so two requests for the same file are handled one after the other.
 * Operations return [ok, current lock value]; the current value goes into X-WOPI-Lock on 409.
 */
class WopiLocks
{
    public const MINUTES = 30;

    public function current(int $nodeId): ?string
    {
        $value = DB::table('x_files_office_locks')->where('node_id', $nodeId)->where('expires_at', '>', now())->value('lock_value');

        return is_string($value) ? $value : null;
    }

    /** @return array{0: bool, 1: string} */
    public function lock(int $nodeId, int $sessionId, string $lock, ?string $oldLock = null): array
    {
        return $this->locked($nodeId, function (?string $current) use ($nodeId, $sessionId, $lock, $oldLock) {
            // LOCK: free or already ours; UnlockAndRelock: only from the given old lock
            $ok = $oldLock === null ? ($current === null || $current === $lock) : $current === $oldLock;
            if ($ok) {
                DB::table('x_files_office_locks')->upsert([[
                    'node_id' => $nodeId, 'session_id' => $sessionId, 'lock_value' => $lock,
                    'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now(),
                ]], ['node_id'], ['session_id', 'lock_value', 'expires_at', 'updated_at']);
            }

            return [$ok, $ok ? $lock : (string) $current];
        });
    }

    /** @return array{0: bool, 1: string} */
    public function refresh(int $nodeId, string $lock): array
    {
        return $this->locked($nodeId, function (?string $current) use ($nodeId, $lock) {
            if ($current !== $lock) {
                return [false, (string) $current];
            }
            DB::table('x_files_office_locks')->where('node_id', $nodeId)->update(['expires_at' => now()->addMinutes(self::MINUTES), 'updated_at' => now()]);

            return [true, $lock];
        });
    }

    /** @return array{0: bool, 1: string} */
    public function unlock(int $nodeId, string $lock): array
    {
        return $this->locked($nodeId, function (?string $current) use ($nodeId, $lock) {
            if ($current !== $lock) {
                return [false, (string) $current];
            }
            DB::table('x_files_office_locks')->where('node_id', $nodeId)->delete();

            return [true, ''];
        });
    }

    public static function valid(string $lock): bool
    {
        return $lock !== '' && strlen($lock) <= 1024 && mb_check_encoding($lock, 'UTF-8') && preg_match('/[\x00-\x1f\x7f]/', $lock) === 0;
    }

    /**
     * @param  callable(?string): array{0: bool, 1: string}  $callback
     * @return array{0: bool, 1: string}
     */
    private function locked(int $nodeId, callable $callback): array
    {
        return DB::transaction(function () use ($nodeId, $callback) {
            Node::withTrashed()->whereKey($nodeId)->lockForUpdate()->firstOrFail();
            DB::table('x_files_office_locks')->where('node_id', $nodeId)->where('expires_at', '<=', now())->delete();

            return $callback($this->current($nodeId));
        });
    }
}
