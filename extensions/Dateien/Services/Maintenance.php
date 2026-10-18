<?php

namespace Extensions\Dateien\Services;

use App\Models\Setting;
use App\Models\User;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Version;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operations for the file store: comparing database and disk (after a restore or a crash),
 * removing confirmed orphans and pruning the audit by the retention setting.
 * Nothing here deletes a file that a version still references.
 */
class Maintenance
{
    public const REPORT = 'dateien.reconcile_report';

    private const KEY = '/\A[0-9a-f-]{36}\/[0-9a-f-]{36}\z/D';

    public function __construct(private readonly FileService $files) {}

    /**
     * @return array{checked_at: string, versions: int, files: int, verified: bool, missing: list<array{version: int, node: int, key: string}>, size_mismatch: list<array{version: int, node: int, key: string}>, checksum_mismatch: list<array{version: int, node: int, key: string}>, orphans: list<array{key: string, size: int}>, stale_staging: int}
     */
    public function reconcile(bool $verify = false): array
    {
        $disk = $this->disk();
        $grace = now()->subMinutes(max(0, (int) config('dateien.orphan_grace_minutes', 60)))->getTimestamp();
        $pending = DB::table('x_files_pending_deletions')->pluck('storage_key')->flip();
        $known = [];
        $report = ['checked_at' => now()->toIso8601String(), 'versions' => 0, 'files' => 0, 'verified' => $verify,
            'missing' => [], 'size_mismatch' => [], 'checksum_mismatch' => [], 'orphans' => [], 'stale_staging' => 0];

        foreach (Version::query()->select(['id', 'node_id', 'storage_key', 'size', 'checksum'])->orderBy('id')->lazyById(500) as $version) {
            $report['versions']++;
            $known[$version->storage_key] = true;
            $entry = ['version' => $version->id, 'node' => $version->node_id, 'key' => $version->storage_key];
            if (! $disk->exists($version->storage_key)) {
                $report['missing'][] = $entry;
            } elseif ($disk->size($version->storage_key) !== $version->size) {
                $report['size_mismatch'][] = $entry;
            } elseif ($verify && ! hash_equals($version->checksum, (string) hash_file('sha256', $disk->path($version->storage_key)))) {
                $report['checksum_mismatch'][] = $entry;
            }
        }

        foreach ($disk->allFiles() as $path) {
            $report['files']++;
            if (str_starts_with($path, '.staging/')) {
                // Interrupted uploads; the next delete of orphans removes them too
                if ($disk->lastModified($path) < now()->subDay()->getTimestamp()) {
                    $report['stale_staging']++;
                }

                continue;
            }
            if (isset($known[$path]) || isset($pending[$path]) || ! preg_match(self::KEY, $path)) {
                continue;
            }
            if ($disk->lastModified($path) <= $grace) {
                $report['orphans'][] = ['key' => $path, 'size' => (int) $disk->size($path)];
            }
        }

        Setting::set(self::REPORT, json_encode($report, JSON_UNESCAPED_SLASHES));

        return $report;
    }

    /** @return array<string, mixed>|null */
    public function lastReport(): ?array
    {
        $report = json_decode((string) Setting::get(self::REPORT, ''), true);

        return is_array($report) ? $report : null;
    }

    public static function problems(array $report): int
    {
        return count($report['missing']) + count($report['size_mismatch']) + count($report['checksum_mismatch']) + count($report['orphans']);
    }

    /**
     * Deletes the orphans of a fresh scan (never the stale list an admin looked at) through the
     * deletion outbox, plus stale staging files. Only admins.
     *
     * @return array{orphans: int, staging: int, failed: int}
     */
    public function deleteOrphans(User $admin): array
    {
        abort_unless($admin->is_admin && $admin->is_active, 403);
        $report = $this->reconcile();
        $disk = $this->disk();
        $bytes = 0;
        foreach ($report['orphans'] as $orphan) {
            // A version may have been created for the key since the scan: never queue it then
            if (! Version::where('storage_key', $orphan['key'])->exists()) {
                DB::table('x_files_pending_deletions')->insertOrIgnore(['storage_key' => $orphan['key'], 'created_at' => now()]);
                $bytes += $orphan['size'];
            }
        }
        $staging = 0;
        foreach ($disk->files('.staging') as $path) {
            if ($disk->lastModified($path) < now()->subDay()->getTimestamp() && $disk->delete($path)) {
                $staging++;
            }
        }
        $result = $this->files->drainPendingDeletions(true);
        AuditEvent::create(['actor_id' => $admin->id, 'action' => 'storage.orphans_deleted', 'metadata' => [
            'orphans' => count($report['orphans']), 'bytes' => $bytes, 'staging' => $staging, 'failed' => $result['failed'],
        ]]);
        $this->reconcile();

        return ['orphans' => count($report['orphans']), 'staging' => $staging, 'failed' => $result['failed']];
    }

    /** Deletes audit events older than the retention (0 = keep forever) and records that it did. */
    public function pruneAudit(): int
    {
        $days = FileService::auditRetentionDays();
        if ($days === 0) {
            return 0;
        }

        return DB::transaction(function () use ($days) {
            DB::select("SELECT set_config('x_files.audit_prune', 'on', true)");
            $deleted = AuditEvent::where('created_at', '<', now()->subDays($days))->delete();
            DB::select("SELECT set_config('x_files.audit_prune', 'off', true)");
            if ($deleted > 0) {
                AuditEvent::create(['action' => 'audit.pruned', 'metadata' => ['deleted' => $deleted, 'retention_days' => $days]]);
            }

            return $deleted;
        });
    }

    /** @return array{count: int, oldest: ?Carbon} */
    public function pendingDeletions(): array
    {
        $oldest = DB::table('x_files_pending_deletions')->min('created_at');

        return ['count' => DB::table('x_files_pending_deletions')->count(), 'oldest' => $oldest ? Carbon::parse($oldest) : null];
    }

    private function disk(): Filesystem
    {
        return $this->files->disk();
    }
}
