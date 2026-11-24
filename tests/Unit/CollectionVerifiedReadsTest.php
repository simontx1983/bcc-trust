<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * CollectionRepository's four guarded reads, now verified (F-472).
 *
 * ## What changed, and why a test was needed at all
 *
 * All four read through the `runRead` family instead of
 * `get_row()`/`get_var()`/`get_results()` followed by
 * `guardReadOrThrow()`. The guard inspects `$wpdb->last_error` AND NOTHING
 * ELSE, so it is blind to wpdb's pre-`flush()` bails: `query()` returns
 * false above `flush()`, no error is recorded, and the PREVIOUS
 * statement's rows are still in `last_result`. `get_row()` then hands back
 * row 0 OF ANOTHER QUERY and the guard reports success.
 *
 * `runRead()` checks `query()`'s return value as well as the error, which
 * is the entire difference.
 *
 * ## Why the policy controls matter as much as the refusals
 *
 * Two of the four carry a caller-chosen policy: `listVerifiedByChain()` /
 * `listVerifiedByChainOrThrow()` and `findTokenStandard()` /
 * `findTokenStandardOrThrow()` are one query behind two policies. The
 * conversion moved that choice into `runRead`'s third argument, so for
 * each pair this file asserts BOTH arms — the fail-closed one throws, and
 * the fail-SAFE one still returns its empty shape and still logs. A change
 * that made everything throw would pass every refusal test here and break
 * the worker paths that depend on carrying on.
 *
 * @see \BCC\Trust\Tests\Integration\CollectionCanonicalIdentityIntegrationTest
 * @see \BCC\Trust\Tests\Integration\CollectionVerifiedOnlySelectionIntegrationTest
 */
#[Group('unit')]
#[CoversClass(CollectionRepository::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionVerifiedReadsTest extends TestCase
{
    /** Fragments that identify each statement under test. */
    private const ADMIN_DETAIL  = 'c.metadata_state, c.metadata_checked_at';
    private const KEY_COLLISION = 'canonical_identifier <>';
    private const VERIFIED_LIST = 'c.is_verified = 1';
    private const TOKEN_STD     = 'SELECT token_standard';
    private const CHAINS        = 'id, slug, name, chain_type';

    private \BccCollectionReadFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/collection-verified-read-stubs.php';

        $GLOBALS['__bcc_coll_log'] = [];
        $this->wpdb                = new \BccCollectionReadFakeWpdb();
        $GLOBALS['wpdb']           = $this->wpdb;
    }

    // ── findForAdminDetail: always fail-closed ──────────────────────────

    public function testAdminDetailRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::ADMIN_DETAIL;

        $this->expectException(RepositoryReadFailure::class);
        try {
            CollectionRepository::findForAdminDetail(7);
        } finally {
            $this->assertTheBailPremiseHeld();
        }
    }

    public function testAdminDetailStillAnswersNullForNoSuchCollection(): void
    {
        self::assertNull(CollectionRepository::findForAdminDetail(7));
        self::assertSame([], $GLOBALS['__bcc_coll_log'], 'an empty answer logged a read failure');
        self::assertNotSame([], $this->wpdb->sentMatching(self::ADMIN_DETAIL), 'the read never ran');
    }

    // ── findChainContractKeyCollision: always fail-closed ───────────────

    public function testKeyCollisionRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::KEY_COLLISION;

        $this->expectException(RepositoryReadFailure::class);
        try {
            CollectionRepository::findChainContractKeyCollision(1, '0xabc', 'evm:0xabc');
        } finally {
            $this->assertTheBailPremiseHeld();
        }
    }

    public function testKeyCollisionReturnsTheCollidingRow(): void
    {
        $this->wpdb->answers[self::KEY_COLLISION] = [
            (object) ['id' => '9', 'contract_address' => '0xabc', 'canonical_identifier' => 'evm:0xother'],
        ];

        $row = CollectionRepository::findChainContractKeyCollision(1, '0xabc', 'evm:0xabc');

        self::assertIsObject($row);
        self::assertSame('9', $row->id);
    }

    // ── listVerifiedByChain: both arms of one query ─────────────────────

    public function testVerifiedByChainOrThrowRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::VERIFIED_LIST;

        $this->expectException(RepositoryReadFailure::class);
        try {
            CollectionRepository::listVerifiedByChainOrThrow(1, 5);
        } finally {
            $this->assertTheBailPremiseHeld();
        }
    }

    /**
     * ⭐ The fail-SAFE arm of the same query. It must NOT throw — the
     * discovery and indexer paths treat "nothing this tick" as a safe
     * answer and an exception there turns a logged degradation into a
     * fatal — but it must still LOG, or the degradation is silent.
     */
    public function testVerifiedByChainFailSafeArmReturnsEmptyAndLogs(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::VERIFIED_LIST;

        self::assertSame([], CollectionRepository::listVerifiedByChain(1, 5));
        self::assertNotSame([], $GLOBALS['__bcc_coll_log']['error'] ?? [], 'the degradation was silent');
    }

    // ── findTokenStandard: both arms of one query ───────────────────────

    public function testTokenStandardOrThrowRefusesABailingRead(): void
    {
        $this->seedChain();
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::TOKEN_STD;

        $this->expectException(RepositoryReadFailure::class);
        CollectionRepository::findTokenStandardOrThrow(1, self::CONTRACT);
    }

    public function testTokenStandardFailSafeArmReturnsNullAndLogs(): void
    {
        $this->seedChain();
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::TOKEN_STD;

        self::assertNull(CollectionRepository::findTokenStandard(1, self::CONTRACT));
        self::assertNotSame([], $GLOBALS['__bcc_coll_log']['error'] ?? [], 'the degradation was silent');
    }

    public function testTokenStandardComesBackFromAHealthyRead(): void
    {
        $this->seedChain();
        $this->wpdb->answers[self::TOKEN_STD] = [(object) ['token_standard' => 'ERC-721']];

        self::assertSame('ERC-721', CollectionRepository::findTokenStandardOrThrow(1, self::CONTRACT));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private const CONTRACT = '0x1111111111111111111111111111111111111111';

    /**
     * ANTI-VACUITY. Put rows where a bail will find them.
     *
     * Without this, `last_result` is empty and the PRE-FIX code would also
     * have produced nothing — every refusal test here would pass against
     * the defect it exists to pin. The rows are deliberately shaped like
     * the reads under test, so the old form would have returned them as
     * answers rather than choking on them.
     */
    private function leaveStaleRowsBehind(): void
    {
        // Assigned directly, because that IS the premise: some earlier
        // statement in the request left these rows in last_result and the
        // bail never clears them. (Where the method under test runs an
        // earlier read of its own — readTokenStandard resolves a chain
        // first — that read overwrites these with its own rows, which are
        // just as foreign to the read that bails.)
        $this->wpdb->last_result = [
            (object) [
                'id'                   => '999',
                'contract_address'     => '0xstale',
                'canonical_identifier' => 'evm:0xstale',
                'token_standard'       => 'ERC-1155',
                'chain_id'             => '1',
            ],
        ];
    }

    /** The chain row `canonicalIdentityFor()` resolves before the read. */
    private function seedChain(): void
    {
        $this->wpdb->answers[self::CHAINS] = [
            (object) ['id' => '1', 'slug' => 'eth', 'name' => 'Ethereum', 'chain_type' => 'evm'],
        ];
    }

    /**
     * The two facts that make it a BAIL rather than a reported failure:
     * no error was recorded, and another statement's rows are still
     * readable where the caller would look for its own.
     */
    private function assertTheBailPremiseHeld(): void
    {
        self::assertSame('', $this->wpdb->last_error, 'premise: the bail records no error');
        self::assertNotSame([], $this->wpdb->last_result, 'premise: stale rows were there to be picked up');
    }
}
