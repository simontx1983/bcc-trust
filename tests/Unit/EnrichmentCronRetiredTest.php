<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `bcc_nft_enrichment_tick` no longer schedules itself, and cannot.
 *
 * ── WHAT WAS RETIRED ────────────────────────────────────────────────────
 * A five-minute recurring hook whose handler,
 * `NftEnrichmentService::runAllChains()`, iterated EVERY active chain and
 * called that chain's metadata provider for any row with a NULL
 * `collection_name`. No capability gate, no per-chain opt-in, no
 * administrator-created discovery run, nothing in the PR 7A ledger — and
 * independent of `BCC_COSMWASM_DISCOVERY_ENABLED` entirely.
 *
 * ⚠ IT WAS DORMANT BY DATA, NOT BY DESIGN. A 2026-09-03 audit measured
 * zero pending rows on staging and production, so it ran and did nothing.
 * But `CosmwasmDiscoveryService` emits `'collection_name' => $name` and
 * that name is NULL whenever a CW-721's `contract_info` carries none — so
 * the first administrator scan that found an unnamed contract would have
 * handed this loop provider work, on a chain CRON picked, minutes after
 * the operator's single authorised action.
 *
 * ── WHY THREE SITES, NOT ONE ────────────────────────────────────────────
 * The schedule was created in three places that each look redundant on
 * their own: an activation block, a `plugins_loaded` self-heal, and the
 * service's own `register()`. That triple is exactly why the CW-721 sweeps
 * needed a whole PR to retire — removing any one of them leaves the other
 * two to restore the event on the next request. All three are asserted
 * here, individually.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EnrichmentCronRetiredTest extends TestCase
{
    private const HOOK = 'bcc_nft_enrichment_tick';

    private static function bootstrap(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/bcc-trust.php');
    }

    // ── (14) the three registration sites are gone ──────────────────────

    /** The cron handler binding. Without it the hook fires into nothing. */
    public function testTheCronCallbackIsNoLongerBound(): void
    {
        self::assertStringNotContainsString(
            "[\\BCC\\Trust\\Onchain\\Services\\NftEnrichmentService::class, 'runAllChains']",
            self::bootstrap(),
            'the enrichment tick must have no handler'
        );
    }

    /** The `plugins_loaded` self-heal — the one that re-armed every request. */
    public function testTheSelfHealingRegistrationIsGone(): void
    {
        self::assertStringNotContainsString(
            '\\BCC\\Trust\\Onchain\\Services\\NftEnrichmentService::register();',
            self::bootstrap(),
            'the enrichment self-heal must not run on plugins_loaded'
        );
    }

    /** The activation-time schedule. */
    public function testActivationDoesNotScheduleTheEnrichmentTick(): void
    {
        $src = self::bootstrap();

        self::assertStringNotContainsString(
            "wp_schedule_event(time() + 90, 'bcc_five_minutes', \\BCC\\Trust\\Onchain\\Services\\NftEnrichmentService::CRON_HOOK)",
            $src,
            'activation must not schedule the enrichment tick'
        );

        // And nothing else schedules it under any spelling.
        self::assertSame(
            0,
            preg_match_all('/wp_schedule_event\([^;]*' . preg_quote(self::HOOK, '/') . '/', $src),
            'no bootstrap path may schedule the enrichment tick'
        );
    }

    // ── (16) repeated registration cannot recreate it ───────────────────

    /**
     * `register()` is kept as an EMPTY method on purpose — it is the thing
     * a future reader is most likely to "restore" from an older build — so
     * this asserts it schedules nothing however many times it is called,
     * rather than asserting the method is absent.
     */
    public function testRepeatedRegistrationSchedulesNothing(): void
    {
        require_once __DIR__ . '/../Stubs/enrichment-retirement-stubs.php';

        \BccEnrichmentCronState::reset();

        for ($i = 0; $i < 10; $i++) {
            \BCC\Trust\Onchain\Services\NftEnrichmentService::register();
        }

        self::assertSame([], \BccEnrichmentCronState::$scheduled, 'register() must schedule nothing');
        self::assertSame(0, \BccEnrichmentCronState::$scheduleCalls);
    }

    /** And the body really is empty — not a schedule behind a condition. */
    public function testTheRegisterBodyContainsNoScheduleCall(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/NftEnrichmentService.php'
        );

        // Strip comments first: the explanation above register() names
        // wp_schedule_event to say why it must NOT come back, and matching
        // prose would make the comment itself the failure.
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        self::assertStringNotContainsString('wp_schedule_event', $code);
    }

    // ── (18)(19) the hook is in the retirement lists ────────────────────

    /** Deactivation and uninstall clear it, like every other retired hook. */
    public function testTheHookIsInTheCleanupOnlyList(): void
    {
        $map = require dirname(__DIR__, 2) . '/includes/cron-hooks.php';

        self::assertContains(self::HOOK, $map['cleanup_only'], 'must be cleared on deactivate/uninstall');
        self::assertArrayNotHasKey(self::HOOK, $map['recurring'], 'must not be an expected recurring hook');
    }

    /**
     * ⚠ AND THE MIGRATION MUST ACTUALLY RE-RUN.
     *
     * The v1 unschedule migration already completed on every install that
     * ran it — its `done_option` was measured present on BOTH staging and
     * production on 2026-09-03 — and the runner skips any migration whose
     * done_option exists. Adding a hook to the shared list WITHOUT a fresh
     * done_option would clear it on new installs only, and leave the live
     * five-minute event running forever on exactly the installs that have
     * one. A second registry entry is required, not optional.
     */
    public function testASecondMigrationEntryExistsWithItsOwnDoneOption(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/includes/database/migration-runner.php'
        );

        self::assertStringContainsString("'unschedule_automatic_nft_discovery_v2'", $src);
        self::assertStringContainsString("'bcc_trust_nft_enrichment_tick_unscheduled'", $src);

        // The v1 entry and its option name are UNCHANGED — renaming either
        // would re-run v1 everywhere for no reason.
        self::assertStringContainsString("'unschedule_automatic_nft_discovery_v1'", $src);
        self::assertStringContainsString("'bcc_trust_automatic_nft_discovery_unscheduled'", $src);

        // Two entries, two distinct done_options.
        self::assertSame(
            2,
            preg_match_all('/unschedule_automatic_nft_discovery_v\d/', $src),
            'exactly two unschedule entries'
        );
    }

    /** The hook is in the single list the migration and its tests share. */
    public function testTheHookIsInTheSharedRetiredList(): void
    {
        require_once dirname(__DIR__, 2) . '/includes/database/unschedule-automatic-nft-discovery.php';

        self::assertContains(self::HOOK, bcc_trust_retired_discovery_hooks());
    }

    // ── (6) the service itself is preserved ─────────────────────────────

    /**
     * The class is NOT deleted. Retiring a schedule and deleting a tested
     * service are two decisions, and only the first is this PR's.
     *
     * `runForChain()` stays public so an explicit administrator action — or
     * an administrator-created discovery run — can still enrich a chain on
     * purpose. What is gone is the loop that chose chains on its own.
     */
    public function testTheEnrichmentServiceIsPreservedForExplicitCallers(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/NftEnrichmentService.php';

        self::assertFileExists($path);

        $src = (string) file_get_contents($path);
        self::assertStringContainsString('public static function runForChain(', $src);
        self::assertStringContainsString('public static function runAllChains(', $src);
    }

    /**
     * ⚠ AND `findPendingEnrichment()` IS NOT BROADENED.
     *
     * It selects on `collection_name IS NULL`. Widening it to "name OR
     * image OR description missing" would turn any future explicit run into
     * a provider loop over every row that legitimately has no image — and
     * PR 7's own image normalisation, which set 100 staging rows'
     * `image_url` from '' to NULL, would have fed exactly that.
     */
    public function testThePendingEnrichmentSelectorIsUnchanged(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Repositories/CollectionRepository.php'
        );

        self::assertMatchesRegularExpression(
            '/function findPendingEnrichment\([^)]*\).*?WHERE chain_id = %d\s*\n\s*AND collection_name IS NULL/s',
            $src,
            'the selector must stay narrow'
        );
        self::assertDoesNotMatchRegularExpression(
            '/function findPendingEnrichment\([^)]*\).*?image_url IS NULL/s',
            $src,
            'image_url must not widen the selector'
        );
    }
}
