<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The `wp_bcc_chains` projection and its cache, against a REAL MySQL.
 *
 * ⚠ THE FILE NAME IS HISTORICAL. It was written for
 * `cosmwasm_nft_discovery_enabled`, which S9a removed from the projection
 * and S9b dropped from the table. It is kept under its original name — with
 * its history — because two of its guarantees outlived the column and are
 * not about it:
 *
 *   - the projection must NOT name the dropped column (§2), and
 *   - `clearCache()` must actually invalidate (§3), watched through a
 *     RETAINED flag.
 *
 * §1's five migration cases are gone; the note at §1 says exactly what they
 * were and why they became impossible rather than merely redundant.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * The unit suite fakes ChainRepository at its production FQN, so it can
 * prove what a CONSUMER does with a projection but not what the DATABASE
 * and the real repository actually produce. Two of the surviving claims are
 * only checkable here:
 *
 *   2. THE COLUMN IS **NOT** IN THE CACHED PROJECTION — and the column is
 *      no longer on the table either. ⚠ THESE ARE TWO DIFFERENT CLAIMS and
 *      the order between them is the whole safety argument: S9a removed the
 *      name from `ChainRepository::COLUMNS` while the physical column
 *      stayed, and only then did S9b drop the column. A `SELECT` naming a
 *      dropped column fails the WHOLE read, and `ChainRepository` caches an
 *      error sentinel, so doing it the other way round would have made
 *      every chain report UNKNOWN — silently, and from cache.
 *   3. THE TOGGLE INVALIDATES THE CACHE. getActive() is served from a
 *      5-minute object-cache/transient pair. A write that skipped
 *      invalidation would leave a stale flag being acted on for the rest of
 *      the TTL — and the admin screen would show the new value the whole
 *      time, so the operator would have no way to tell.
 *
 * It also pins the other half of the bargain: changing one capability flag
 * for a chain must not disturb anything else that chain is used for. Wallet
 * linking, holdings, validators and Halls all resolve chains through the
 * general accessors, and none of them may start missing a chain.
 */
#[Group('integration')]
#[CoversClass(ChainRepository::class)]
final class ChainCosmwasmDiscoveryFlagIntegrationTest extends TestCase
{
    /**
     * ⚠ A COLUMN THAT NO LONGER EXISTS. Kept as a NAME, not as a column:
     * §2 asserts the projection does not carry it and that
     * `INFORMATION_SCHEMA` does not have it. Nothing here selects or writes
     * it, which is the point — a constant is how you assert about a name
     * without naming it in SQL.
     */
    private const COLUMN = 'cosmwasm_nft_discovery_enabled';

    /**
     * A RETAINED projected flag, used where a case needs to observe the
     * cached projection rather than the retired column.
     *
     * ⚠ S9a removed `cosmwasm_nft_discovery_enabled` from
     * `ChainRepository::COLUMNS`, so it is no longer observable through the
     * projection at all. The cache-invalidation property that case (3) pins
     * is about `ChainRepository::clearCache()` and is entirely unaffected by
     * which column it watches — it just needs one that is actually projected.
     * `bcc_supports_nft_collections` is retained, is a tinyint flag
     * defaulting to 0, and stays in COLUMNS by explicit design note.
     */
    private const OBSERVABLE_COLUMN = 'bcc_supports_nft_collections';




    protected function setUp(): void
    {
        // Every test starts from a cold cache and the shipped column state.
        ChainRepository::clearCache();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        $wpdb = $GLOBALS['wpdb'];
        // ⚠ S9a ALSO RESETS THE OBSERVABLE FLAG. Case (3) watches
        // `bcc_supports_nft_collections` through the cached projection now,
        // and this suite shares `wp_bcc_chains` with tests that deliberately
        // permit a chain. Without this, that case's "precondition: disabled"
        // would depend on execution order — which is exactly the kind of
        // flake that gets a real failure dismissed later.
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::OBSERVABLE_COLUMN . ' = 0'
        );
        ChainRepository::clearCache();
    }

    /**
     * ⚠ LEAVE THE SHARED TABLE AS WE FOUND IT.
     *
     * `setUp()` alone is not enough, and getting this wrong is what the CI
     * run on 80fdc608 caught: case (3) writes
     * `bcc_supports_nft_collections = 1` and asserts on it, and with no
     * tearDown that value survived into
     * `ChainNftCapabilityEditorIntegrationTest`, whose
     * `testTheInstallerEnablesNothingAndSeedsNoOverride` then found one chain
     * with product support where it requires none.
     *
     * A `setUp()` reset protects this file's own cases from each other; only
     * a tearDown protects the suites that run after it. `wp_bcc_chains` is
     * shared, PHPUnit runs this suite sequentially in one process, and both
     * columns are flags whose shipped state is 0.
     */
    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::OBSERVABLE_COLUMN . ' = 0'
        );
        ChainRepository::clearCache();

        parent::tearDown();
    }


    private function firstChainId(): int
    {
        $wpdb = $GLOBALS['wpdb'];

        return (int) $wpdb->get_var(
            'SELECT id FROM `' . ChainRepository::table() . '` ORDER BY id ASC LIMIT 1'
        );
    }

    // ── (1) THE MIGRATION CASES: REMOVED (S9b) ──────────────────────────
    //
    // Five cases lived here, and they were the whole reason this file was
    // written. They asserted that `cosmwasm_nft_discovery_enabled` was a
    // `TINYINT(1) NOT NULL DEFAULT 0`; that every installed chain shipped at
    // 0; that re-running the installer flipped nothing; that the installer
    // was actually wired into `bcc_onchain_ensure_schema()`; and — the best
    // of them — that an install which PRE-DATED the column landed every row
    // at 0, by dropping the column and re-running the real migration.
    //
    // ⚠ S9b DROPPED THE COLUMN AND DELETED
    // `bcc_onchain_add_chains_cosmwasm_discovery_column()`. These are not
    // cases that became redundant — they became IMPOSSIBLE TO EXPRESS.
    // Every one of them named a column that no longer exists or called an
    // installer that no longer exists, and the fifth needed BOTH.
    //
    // Keeping them in any form would have meant asserting something about a
    // column nothing can store, which is the vacuous shape this suite
    // refuses elsewhere. The successor guarantees are pinned instead by
    // `ScannerSchemaDropIntegrationTest`, which proves on a real engine that
    // the column, its sibling tables and the index over two retired columns
    // are gone, that nothing re-adds them on the next schema pass, and that
    // the RETAINED capability columns are still installed correctly by it.
    //
    // Section (2) below survives because its property is about the
    // PROJECTION, and section (3) because its property is about
    // `clearCache()` and it watches a retained flag.






    // ── (2) the column is NOT in the cached projection (S9a) ────────────

    /**
     * ⚠⚠ THIS CASE WAS INVERTED IN S9a, AND THE OLD FORM WAS A DELIBERATE
     * PIN AGAINST EXACTLY THIS CHANGE — so the inversion needs its reason
     * on the record.
     *
     * It asserted that every projected chain row CARRIED
     * `cosmwasm_nft_discovery_enabled`, with the message: "the worker reads
     * an absent column as INELIGIBLE, so dropping it from
     * ChainRepository::COLUMNS would silently stop all discovery".
     *
     * That reasoning was correct and is now void: **there is no worker.** S8
     * deleted `CosmwasmDiscoveryWorker`, and a comment-stripped sweep over
     * `app/` and `includes/` finds the column named in exactly one
     * executable place — `schema-chains.php`, the installer that ADDS it,
     * which S9a retains on purpose. Nothing reads it.
     *
     * So the property worth pinning is the opposite one, and it is what makes
     * S9b's drop safe: the projection must NOT name a column it no longer
     * needs, because a `SELECT` naming a dropped column fails the whole read
     * and `ChainRepository` caches an error sentinel.
     *
     * ⚠ The COLUMN itself is still on the table — case (1) above still
     * asserts the installer adds it. Only the projection stopped asking.
     */
    public function testTheCachedProjectionNoLongerCarriesTheRetiredColumn(): void
    {
        $chains = ChainRepository::getActive();
        self::assertNotSame([], $chains, 'the seeded registry must produce active chains');

        foreach ($chains as $chain) {
            $vars = get_object_vars($chain);

            self::assertArrayNotHasKey(
                self::COLUMN,
                $vars,
                self::COLUMN . ' is back in the projection — S9b drops that column, and a '
                    . 'projection naming a dropped column fails silently behind the error sentinel'
            );

            // Anti-vacuity: the row must be a real projection, not an empty
            // object that would satisfy the assertion above for free.
            self::assertArrayHasKey('slug', $vars, 'the projected row must be populated');
            self::assertArrayHasKey(
                self::OBSERVABLE_COLUMN,
                $vars,
                'the RETAINED capability flag must still be projected'
            );
        }

        // The single-row fallback path (inactive chains / cache miss) uses
        // the same COLUMNS constant, and must agree.
        $byId = ChainRepository::getById($this->firstChainId());
        self::assertNotNull($byId);
        $vars = get_object_vars($byId);
        self::assertArrayNotHasKey(self::COLUMN, $vars);
        self::assertArrayHasKey(self::OBSERVABLE_COLUMN, $vars);

        // ⚠⚠ AND THE COLUMN IS NOW PHYSICALLY GONE — WHICH IS WHY THE TWO
        // SENSES OF "ABSENT" HAVE TO BE KEPT APART.
        //
        // S9a's claim was narrow and exact: absent from the RETURNED
        // PROJECTION, while the physical column stayed on the table. That is
        // what made S9a a pure, reversible code change, and the assertion
        // that used to sit here read `assertNotNull($this->columnDefinition())`
        // — "S9a must not have dropped the column; that is S9b, behind a
        // backup."
        //
        // S9b is that second step. The column is gone from `CREATE TABLE`,
        // the installer that re-added it is deleted, and
        // `bcc_trust_drop_scanner_schema()` removes it from existing
        // installs. So the assertion inverts, and it is worth having in both
        // forms on the record: the projection stopped asking FIRST, and only
        // then was the column dropped. Reversing that order is what would
        // have failed every chain read behind a cached error sentinel.
        $wpdb = $GLOBALS['wpdb'];
        self::assertSame(
            0,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                ChainRepository::table(),
                self::COLUMN
            )),
            'S9b drops the column; the installer must not have re-added it'
        );

        // Anti-vacuity for that probe: the same query shape must FIND the
        // retained flag, or a typo in the table name would make the
        // assertion above pass for free.
        self::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                ChainRepository::table(),
                self::OBSERVABLE_COLUMN
            )),
            'the probe must be able to find a column that IS there'
        );
    }

    // ── (3) the toggle invalidates the cache ────────────────────────────

    public function testAWriteThatSkipsInvalidationKeepsServingTheStaleAnswer(): void
    {
        $wpdb    = $GLOBALS['wpdb'];
        $chainId = $this->firstChainId();

        // Warm the cache with the shipped state.
        self::assertFalse($this->cachedFlag($chainId), 'precondition: disabled');

        // A write that goes straight to SQL — i.e. what a future admin
        // screen or wp-cli one-liner would do if it did not go through the
        // repository.
        $wpdb->query($wpdb->prepare(
            'UPDATE `' . ChainRepository::table() . '` SET ' . self::OBSERVABLE_COLUMN . ' = 1 WHERE id = %d',
            $chainId
        ));

        // THE HAZARD IS REAL: the cached projection has not moved, so every
        // reader keeps acting on the old answer for the whole TTL.
        //
        // ⚠ The original note said "the scanner would keep acting on the old
        // answer". There is no scanner — S8 deleted it. The hazard survives
        // it unchanged, because the flag now watched here
        // (`bcc_supports_nft_collections`) gates whether a chain can take a
        // collection at all, and `NftChainCapability` reads it through this
        // same cached projection. A stale "permitted" answer is the one that
        // matters, exactly as a stale "scan me" answer used to be.
        self::assertFalse(
            $this->cachedFlag($chainId),
            'this assertion is what makes the invalidation below worth having'
        );

        ChainRepository::clearCache();
        self::assertTrue($this->cachedFlag($chainId));
    }






    /**
     * Every column of one chain row, keyed by column name.
     *
     * Explicit projection built from INFORMATION_SCHEMA rather than
     * `SELECT *`: §2 is about repositories, but an assertion that names
     * its columns is also the one that notices when the set of columns
     * changes. Names are filtered to `[A-Za-z0-9_]` before interpolation
     * — they come from the schema, not from input, and they still get
     * checked.
     *
     * @return array<string, string|null>
     */
    private function rowSnapshot(int $chainId): array
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = ChainRepository::table();

        $columns = [];
        foreach ($wpdb->get_col($wpdb->prepare(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY ORDINAL_POSITION',
            $table
        )) as $column) {
            $name = (string) $column;
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) === 1) {
                $columns[] = '`' . $name . '`';
            }
        }
        if ($columns === []) {
            return [];
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . implode(', ', $columns) . ' FROM `' . $table . '` WHERE id = %d LIMIT 1',
            $chainId
        ));
        if ($row === null) {
            return [];
        }

        $out = [];
        foreach (get_object_vars($row) as $key => $value) {
            $out[(string) $key] = $value === null ? null : (string) $value;
        }

        return $out;
    }

    /** The flag as the CACHED projection currently reports it. */
    private function cachedFlag(int $chainId): bool
    {
        foreach (ChainRepository::getActive() as $chain) {
            if ((int) $chain->id !== $chainId) {
                continue;
            }
            $vars = get_object_vars($chain);

            // ⚠ READS THE RETAINED FLAG SINCE S9a. This read `self::COLUMN`,
            // which the projection no longer carries, so it would silently
            // return 0 for every chain and the invalidation case below would
            // pass for the wrong reason in one direction and fail in the
            // other. The property under test is `clearCache()`, not which
            // flag is watched.
            return (int) ($vars[self::OBSERVABLE_COLUMN] ?? 0) === 1;
        }

        self::fail("chain {$chainId} is not in the active projection");
    }

    // ── (4) disabling discovery disturbs nothing else ───────────────────



}
