<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pins includes/cron-hooks.php — the single source of truth shared by
 * the bcc_expected_cron_hooks drift contributor, bcc_trust_deactivate(),
 * and uninstall.php.
 *
 * The file is pure literals (it must load without the plugin autoloader
 * for uninstall.php), so the test requires it directly.
 *
 * Regression anchors: the deactivate/uninstall lists previously drifted
 * from the actually-scheduled set — this fails if a drift-tracked hook
 * goes missing or if the two sets start to overlap.
 */
final class CronHookRegistryTest extends TestCase
{
    /** @return array{recurring: array<string, array{interval: string, description: string}>, cleanup_only: list<string>} */
    private static function load(): array
    {
        /** @var array{recurring: array<string, array{interval: string, description: string}>, cleanup_only: list<string>} $lists */
        $lists = require __DIR__ . '/../../includes/cron-hooks.php';
        return $lists;
    }

    public function testShapeIsWellFormed(): void
    {
        $lists = self::load();

        self::assertArrayHasKey('recurring', $lists);
        self::assertArrayHasKey('cleanup_only', $lists);
        self::assertNotSame([], $lists['recurring']);

        foreach ($lists['recurring'] as $hook => $meta) {
            self::assertIsString($hook);
            self::assertArrayHasKey('interval', $meta, "$hook missing interval");
            self::assertArrayHasKey('description', $meta, "$hook missing description");
            self::assertNotSame('', $meta['interval']);
            self::assertNotSame('', $meta['description']);
        }
        foreach ($lists['cleanup_only'] as $hook) {
            self::assertIsString($hook);
            self::assertNotSame('', $hook);
        }
    }

    /**
     * Sentinel hooks that were MISSING from one or both cleanup lists
     * before this batch — every domain must be represented so nothing is
     * orphaned in the cron array after plugin delete.
     */
    public function testDriftTrackedSentinelsPresent(): void
    {
        $recurring = self::load()['recurring'];

        foreach ([
            'bcc_trust_divergence_state_sweep',   // missing from deactivate
            'bcc_trust_daily_contribution_recovery',
            'bcc_trust_weekly_digest',
            'bcc_disputes_reconcile_orphans',     // whole Disputes domain missing from uninstall
            'bcc_disputes_auto_resolve',
            'bcc_helius_dedupe_sweep',            // whole Onchain domain missing from uninstall
            'bcc_nft_eth_indexer_tick',
            'bcc_watch_batch_sweep',
            'bcc_attestor_reliability_sweep',
        ] as $hook) {
            self::assertArrayHasKey($hook, $recurring, "$hook must be drift-tracked");
        }
    }

    public function testRecurringAndCleanupAreDisjoint(): void
    {
        $lists   = self::load();
        $overlap = array_intersect(array_keys($lists['recurring']), $lists['cleanup_only']);
        self::assertSame([], $overlap, 'A hook must not be both drift-tracked and cleanup-only: ' . implode(',', $overlap));
    }
}
