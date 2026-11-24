<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The remaining six guarded onchain reads, now verified (F-472).
 *
 * `guardRead()`, `guardReadOrThrow()` and `readFailed()` inspect
 * `$wpdb->last_error` and nothing else, so none of them can see wpdb's
 * pre-`flush()` bails: `query()` returns false above `flush()`, records no
 * error, and leaves the PREVIOUS statement's rows in `last_result`. Each of
 * these reads now goes through the `runRead` family, which checks
 * `query()`'s return value as well.
 *
 * Three of the six carry a caller-chosen policy, and for those this file
 * asserts BOTH arms. A change that made everything throw would pass every
 * refusal test here while breaking the worker paths that depend on carrying
 * on with an empty result.
 *
 * Two sites are worth reading closely rather than skimming:
 *
 *   - `addCuUsage()` reads `FOR UPDATE` inside an explicit transaction. Its
 *     `catch (\Throwable)` already rolled back and logged, so the throw is
 *     absorbed — but a bail used to arrive as `$row === null`, i.e. "no
 *     such chain", and the day's CU usage was dropped with nothing logged
 *     and the row lock never held. The test asserts the ROLLBACK and the
 *     log, not an exception.
 *
 *   - `NftSpamContractRepository::getRule()` must never CACHE a failed
 *     read, and the fail-safe arm of `runReadVar()` answers null, which is
 *     exactly the value it must not cache. So that site reads fail-closed
 *     either way and applies the caller's policy to the exception. The test
 *     calls it twice and asserts the query ran BOTH times.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainVerifiedReadsTest extends TestCase
{
    private const CU_READ     = 'FOR UPDATE';
    private const GATED_READ  = 'SELECT pm_coll.post_id';
    private const COUNT_READ  = 'CAST(SUM(balance) AS UNSIGNED)';
    private const LOSS_READ   = 'SELECT MAX(indexed_at)';
    private const SPAM_READ   = 'SELECT rule';
    private const WALLET_READ = 'w.wallet_address';

    private \BccOnchainReadFakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/onchain-verified-read-stubs.php';

        $GLOBALS['__bcc_onchain_log'] = [];
        $this->wpdb                   = new \BccOnchainReadFakeWpdb();
        $GLOBALS['wpdb']              = $this->wpdb;
    }

    // ── ChainCheckpointRepository::addCuUsage ───────────────────────────

    /**
     * A FOR UPDATE read that did not run must not be read as "no such
     * chain". The transaction rolls back and the drop is LOGGED.
     *
     * ⚠ This site's premise differs from every other one here, and
     * measuring it is what said so rather than assuming. `START
     * TRANSACTION` goes through `query()` and therefore through `flush()`,
     * so by the time the read bails `last_result` is ALREADY empty. No
     * stale rows can reach this caller, and asserting that they could was
     * simply wrong.
     *
     * What was wrong here is subtler and still real: the bail records NO
     * error, so the old `last_error` guard saw success, `get_row()`
     * answered null, and null means "no such chain" on the very next line.
     * The day's CU usage was dropped, the row lock this statement exists to
     * take had never been held, and nothing was logged. The verified read
     * turns that into a throw, which the existing `catch (\Throwable)`
     * rolls back and logs.
     */
    public function testCuUsageRollsBackAndLogsWhenItsLockedReadDidNotRun(): void
    {
        $this->wpdb->bailOn = self::CU_READ;

        self::assertSame(0, ChainCheckpointRepository::addCuUsage(1, 25));

        // The one premise that holds here, and the one that made the old
        // guard blind: the bail records no error at all.
        self::assertSame('', $this->wpdb->last_error, 'premise: the bail records no error');
        self::assertNotSame([], $this->wpdb->sentMatching('ROLLBACK'), 'the transaction was left open');
        self::assertSame([], $this->wpdb->updates, 'CU usage was written from an untrusted read');
        self::assertNotSame(
            [],
            $GLOBALS['__bcc_onchain_log']['error'] ?? [],
            'the dropped CU usage was silent'
        );
    }

    /** Control: a healthy read still records the usage. */
    public function testCuUsageIsRecordedFromAHealthyRead(): void
    {
        $this->wpdb->answers[self::CU_READ] = [
            (object) ['cu_used_today' => '10', 'cu_budget_reset_at' => '2026-01-01'],
        ];

        self::assertSame(35, ChainCheckpointRepository::addCuUsage(1, 25));
        self::assertNotSame([], $this->wpdb->updates, 'nothing was written');
    }

    // ── GatedGroupRepository (get_var, always fail-closed) ──────────────

    public function testHolderGroupLookupRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::GATED_READ;

        $this->expectException(RepositoryReadFailure::class);
        try {
            GatedGroupRepository::findPublishedGroupIdForCollectionId(5);
        } finally {
            $this->assertTheBailPremiseHeld();
        }
    }

    public function testHolderGroupLookupAnswersNullWhenNoGroupIsPublished(): void
    {
        self::assertNull(GatedGroupRepository::findPublishedGroupIdForCollectionId(5));
        self::assertSame([], $GLOBALS['__bcc_onchain_log'], 'an empty answer logged a read failure');
    }

    // ── NftHoldingsRepository::countVisibleByContract (both arms) ───────

    public function testVisibleCountOrThrowRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::COUNT_READ;

        $this->expectException(RepositoryReadFailure::class);
        NftHoldingsRepository::countVisibleByContractOrThrow(1, '0xabc', [7, 8]);
    }

    /** The fail-SAFE arm must still answer, and must still log. */
    public function testVisibleCountFailSafeArmReturnsEmptyAndLogs(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::COUNT_READ;

        self::assertSame([], NftHoldingsRepository::countVisibleByContract(1, '0xabc', [7, 8]));
        self::assertNotSame([], $GLOBALS['__bcc_onchain_log']['error'] ?? [], 'the degradation was silent');
    }

    // ── NftHoldingsRepository::latestLossAtOrThrow (get_var) ────────────

    public function testLatestLossRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::LOSS_READ;

        $this->expectException(RepositoryReadFailure::class);
        NftHoldingsRepository::latestLossAtOrThrow(7, '0xabc');
    }

    public function testLatestLossAnswersNullWhenNothingWasEverLost(): void
    {
        self::assertNull(NftHoldingsRepository::latestLossAtOrThrow(7, '0xabc'));
    }

    // ── NftSpamContractRepository::getRule — the cache site ─────────────

    public function testSpamRuleOrThrowRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::SPAM_READ;

        $this->expectException(RepositoryReadFailure::class);
        NftSpamContractRepository::getRuleOrThrow(1, '0xabc');
    }

    /**
     * ⭐ The reason this site is not a mechanical swap: the fail-safe arm
     * still answers null, but that null MUST NOT be cached. One transient
     * fault would otherwise freeze "no rule" in for the whole request, and
     * later callers could not detect it because no query of theirs would
     * run at all.
     *
     * So this calls it twice and asserts the statement was sent BOTH times.
     */
    public function testAFailedSpamRuleReadIsNotCached(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::SPAM_READ;

        self::assertNull(NftSpamContractRepository::getRule(1, '0xabc'));
        self::assertNull(NftSpamContractRepository::getRule(1, '0xabc'));

        self::assertCount(
            2,
            $this->wpdb->sentMatching(self::SPAM_READ),
            'the second call was answered from a cache poisoned by the first failure'
        );
        self::assertNotSame([], $GLOBALS['__bcc_onchain_log']['error'] ?? [], 'the failure was silent');
    }

    /** Control: a REAL answer is still cached, so the query runs once. */
    public function testARealSpamRuleAnswerIsStillCached(): void
    {
        $this->wpdb->answers[self::SPAM_READ] = [(object) ['rule' => 'deny']];

        self::assertSame('deny', NftSpamContractRepository::getRule(1, '0xabc'));
        self::assertSame('deny', NftSpamContractRepository::getRule(1, '0xabc'));

        self::assertCount(
            1,
            $this->wpdb->sentMatching(self::SPAM_READ),
            'the request-scoped cache stopped working'
        );
    }

    // ── WalletRepository::getForUser (both arms) ────────────────────────

    public function testWalletsOrThrowRefusesABailingRead(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::WALLET_READ;

        $this->expectException(RepositoryReadFailure::class);
        WalletRepository::getForUserOrThrow(3);
    }

    /** The fail-SAFE arm must still answer, and must still log. */
    public function testWalletsFailSafeArmReturnsEmptyAndLogs(): void
    {
        $this->leaveStaleRowsBehind();
        $this->wpdb->bailOn = self::WALLET_READ;

        self::assertSame([], WalletRepository::getForUser(3));
        self::assertNotSame([], $GLOBALS['__bcc_onchain_log']['error'] ?? [], 'the degradation was silent');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * ANTI-VACUITY. Put rows where a bail will find them.
     *
     * Assigned directly, because that IS the premise: some earlier
     * statement left these rows in `last_result` and the bail never clears
     * them. Without it the pre-fix code would also have produced nothing,
     * and every refusal test here would pass against the defect it exists
     * to pin.
     */
    private function leaveStaleRowsBehind(): void
    {
        $this->wpdb->last_result = [
            (object) [
                'cu_used_today'      => '4242',
                'cu_budget_reset_at' => '1999-01-01',
                'post_id'            => '999',
                'rule'               => 'allow',
                'wallet_link_id'     => '999',
                'total'              => '99',
            ],
        ];
    }

    /**
     * The two facts that make it a BAIL and not a reported failure: no
     * error was recorded, and another statement's rows are still readable
     * where the caller would look for its own.
     */
    private function assertTheBailPremiseHeld(): void
    {
        self::assertSame('', $this->wpdb->last_error, 'premise: the bail records no error');
        self::assertNotSame([], $this->wpdb->last_result, 'premise: stale rows were there to be picked up');
    }
}
