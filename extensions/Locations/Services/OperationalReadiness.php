<?php

declare(strict_types=1);

namespace Extensions\Locations\Services;

use RuntimeException;
use Throwable;

class OperationalReadiness
{
    public const FAILURE = 'Betriebsbereitschaft nicht prüfbar: PostgreSQL, Schutzmanifest und Schema prüfen. Keine Freigabe; es wurden keine Daten geändert.';

    private const BLOCKERS = [
        'p1_private_acceptance' => 'P1: Private fachliche Abnahme ausstehend.',
        'p3_operator_ownership_restore' => 'P3: Reale Betreiberverantwortung und Wiederherstellungsnachweis ausstehend.',
        'p4_p5_remaining_module_paths' => 'P4/P5: Verbleibende Modulpfade und deren Berechtigungsprüfung ausstehend.',
        'p6_module_views_profile_policy' => 'P6: Modul- und Ansichtsintegration sowie Freigabe der Profilrichtlinie ausstehend.',
        'p7_operational_release_acceptance' => 'P7: Browser-, Mobil-, Offline- und Einrichtungsprüfung sowie Betreiberfreigabe ausstehend.',
    ];

    private const FINDINGS = [
        'tables' => ['null_ownership', 'missing_location', 'inactive_location'],
        'relations' => ['null_reference', 'missing_parent', 'parent_null_ownership', 'child_null_ownership', 'location_disagreement_review'],
    ];

    public function __construct(private readonly LocationRelationInventory $inventory) {}

    public function report(): array
    {
        $base = [
            'read_only' => true,
            'release_ready' => false,
            'enforcement_verified' => false,
            'scope' => 'bounded_operational_readiness',
            'release_blockers' => self::BLOCKERS,
        ];
        try {
            $snapshot = $this->inventory->report();
            $technical = $this->technical($snapshot);

            return [...$base, 'status' => 'blocked', 'technical_inventory' => $technical];
        } catch (Throwable) {
            // Exception payloads and manifest text must never reach either output format.
            return [
                ...$base,
                'status' => 'unavailable',
                'technical_inventory' => ['status' => 'unavailable', 'summary' => null, 'findings' => null],
                'error' => 'operational_readiness_unavailable',
            ];
        }
    }

    private function technical(array $snapshot): array
    {
        if (($snapshot['read_only'] ?? null) !== true
            || ($snapshot['enforcement_verified'] ?? null) !== false
            || ($snapshot['automatic_target_inference'] ?? null) !== false
            || ($snapshot['snapshot'] ?? null) !== 'postgresql_repeatable_read_read_only'
            || ($snapshot['scope'] ?? null) !== 'finite_manifest_relations_readiness'
            || ($snapshot['row_scope'] ?? null) !== 'all_rows_including_historical_and_soft_deleted'
            || ($snapshot['review_policy'] ?? null) !== 'candidates_not_confirmed_violations'
            || ($snapshot['edge_count_policy'] ?? null) !== 'per_edge_counts_not_unique_affected_totals') {
            throw new RuntimeException;
        }
        foreach (['tables', 'relations', 'limitations'] as $key) {
            if (! is_array($snapshot[$key] ?? null) || ! array_is_list($snapshot[$key])) {
                throw new RuntimeException;
            }
        }
        if ($snapshot['tables'] === [] || $snapshot['relations'] === []) {
            throw new RuntimeException;
        }
        $summary = [
            'tables' => count($snapshot['tables']),
            'relations' => count($snapshot['relations']),
            'limitations' => count($snapshot['limitations']),
        ];
        $findings = [];
        foreach (self::FINDINGS as $group => $categories) {
            $summary[$group.'_not_inspected'] = 0;
            foreach ($categories as $category) {
                $findings[$category] = ['count' => 0, 'observed_count' => 0, 'uninspected' => 0];
            }
            foreach ($snapshot[$group] as $entry) {
                if (! is_array($entry) || ! is_string($entry['availability'] ?? null)
                    || ! array_key_exists('counts', $entry)) {
                    throw new RuntimeException;
                }
                $available = $entry['availability'] === 'available';
                if (! $available) {
                    $summary[$group.'_not_inspected']++;
                }
                $counts = $entry['counts'];
                if ($counts !== null && ! is_array($counts)) {
                    throw new RuntimeException;
                }
                foreach ($categories as $category) {
                    if (is_array($counts) && ! array_key_exists($category, $counts)) {
                        throw new RuntimeException;
                    }
                    $value = $counts[$category] ?? null;
                    if ($value !== null && (! is_int($value) || $value < 0)) {
                        throw new RuntimeException;
                    }
                    if (! $available || $value === null) {
                        $findings[$category]['uninspected']++;
                    } else {
                        $findings[$category]['observed_count'] += $value;
                    }
                }
            }
            foreach ($categories as $category) {
                $findings[$category]['count'] = $findings[$category]['uninspected'] > 0
                    ? null : $findings[$category]['observed_count'];
            }
        }

        return [
            'status' => 'CHECK',
            'snapshot' => 'postgresql_repeatable_read_read_only',
            'review_policy' => 'candidates_not_confirmed_violations',
            'edge_count_policy' => 'per_edge_counts_not_unique_affected_totals',
            'summary' => $summary,
            'findings' => $findings,
        ];
    }
}
