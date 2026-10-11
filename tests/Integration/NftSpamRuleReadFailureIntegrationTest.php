<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "No rule" and "could not look" are different facts.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `getRule()` returned null for a genuinely absent rule AND for a failed
 * query, and then CACHED that null. Three production callers read null as
 * "not denied":
 *
 *   NftSpamFilter::isSpam()      falls through to heuristics
 *   CollectionStanceService      surfaces the row to the viewer
 *   EvmFetcher                   accepts the collection
 *
 * …so one unreadable query silently reversed an operator's hide decision,
 * on two user-facing surfaces. The admin detail view printed "not hidden".
 *
 * ⚠⚠ AND THE CACHE AMPLIFIED IT. The failed read's null was stored, so
 * every later caller in the same request got "no rule" WITHOUT RUNNING A
 * QUERY — they could not have detected the fault even by checking, because
 * no query of theirs ever ran.
 *
 * ── WHY THIS IS AN INTEGRATION TEST ─────────────────────────────────────
 * The distinction only exists against a real database: it is the
 * difference between a query that returns no rows and a query that does
 * not run. A stubbed repository returning null twice proves nothing. The
 * failure is injected by renaming the table out from under the read, which
 * is as close to the real fault as a test can get.
 */
#[Group('integration')]
#[Group('mariadb')]
final class NftSpamRuleReadFailureIntegrationTest extends TestCase
{
    private const CHAIN_ID = 1;

    /** Has a DENY rule. */
    private const DENIED = '0xdddddddddddddddddddddddddddddddddddddddd';

    /** Has an ALLOW rule. */
    private const ALLOWED = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** Has no rule at all — genuinely absent. */
    private const UNRULED = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = NftSpamContractRepository::table();

        $wpdb->query("DELETE FROM `{$table}`");
        foreach ([[self::DENIED, 'deny'], [self::ALLOWED, 'allow']] as [$contract, $rule]) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$table}` (chain_id, contract_address, rule, created_at)
                 VALUES (%d, %s, %s, NOW())",
                self::CHAIN_ID,
                strtolower($contract),
                $rule
            ));
        }

        NftSpamContractRepository::flushCache();
    }

    protected function tearDown(): void
    {
        // Make sure a failed test cannot leave the table renamed away.
        $this->restoreTable();
        NftSpamContractRepository::flushCache();
    }

    private function breakTable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = NftSpamContractRepository::table();
        $wpdb->query("RENAME TABLE `{$table}` TO `{$table}_away`");
        NftSpamContractRepository::flushCache();
    }

    private function restoreTable(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = NftSpamContractRepository::table();
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE table_schema = DATABASE() AND table_name = %s",
            $table . '_away'
        ));
        if ($exists === 1) {
            $wpdb->query("RENAME TABLE `{$table}_away` TO `{$table}`");
        }
    }

    // ── 0. CONTROLS: the happy path works, so a later null means something
    // ═══════════════════════════════════════════════════════════════════

    public function testAPresentDenyRuleIsFound(): void
    {
        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED)
        );
    }

    public function testAPresentAllowRuleIsFound(): void
    {
        self::assertSame(
            NftSpamContractRepository::RULE_ALLOW,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::ALLOWED)
        );
    }

    public function testAGenuinelyAbsentRuleIsNull(): void
    {
        // ⚠ THE CONTROL THAT GIVES THE REST THEIR MEANING. Without a case
        // where the table is readable and the answer really is "no rule",
        // every null below could be a read failure in disguise.
        self::assertNull(NftSpamContractRepository::getRule(self::CHAIN_ID, self::UNRULED));
    }

    public function testAGenuinelyAbsentRuleDoesNotThrow(): void
    {
        // The fail-closed arm must distinguish absence from failure, not
        // treat every null as a fault.
        self::assertNull(NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::UNRULED));
    }

    public function testTheFailClosedArmReturnsTheSameRulesAsTheTolerantOne(): void
    {
        self::assertSame(
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED),
            NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::DENIED)
        );
        NftSpamContractRepository::flushCache();
        self::assertSame(
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::ALLOWED),
            NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::ALLOWED)
        );
    }

    // ── 1. ⚠⚠ UNREADABLE IS DISTINGUISHABLE FROM ABSENT
    // ═══════════════════════════════════════════════════════════════════

    public function testAnUnreadableTableThrowsFromTheFailClosedArm(): void
    {
        $this->breakTable();

        $this->expectException(RepositoryReadFailure::class);
        NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::DENIED);
    }

    public function testTheSameLookupThatThrowsWhenBrokenReturnsTheRuleWhenReadable(): void
    {
        // ⚠ The pair that proves the two states are TOLD APART rather than
        // one of them simply never occurring. Same chain, same contract,
        // same method — only the table's readability differs.
        $this->breakTable();
        $threw = false;
        try {
            NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::DENIED);
        } catch (RepositoryReadFailure $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'unreadable must throw');

        $this->restoreTable();
        NftSpamContractRepository::flushCache();

        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::DENIED),
            'readable must answer'
        );
    }

    public function testAnUnreadableTableAndAnAbsentRuleDoNotProduceTheSameOutcome(): void
    {
        // Absent: null, no exception.
        $absent = NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::UNRULED);
        self::assertNull($absent);

        // Unreadable: exception, no value at all.
        $this->breakTable();
        $threw = false;
        try {
            NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, self::UNRULED);
        } catch (RepositoryReadFailure $e) {
            $threw = true;
        }

        self::assertTrue(
            $threw,
            'the SAME contract that is legitimately absent must not be reported as absent when the table cannot be read'
        );
    }

    // ── 2. ⚠⚠ A FAILED READ IS NOT CACHED
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedReadIsNotCachedAndDoesNotPoisonLaterCallers(): void
    {
        // The amplification bug: the old code cached the failure's null, so
        // the next caller in the request got "no rule" without querying and
        // could not detect the fault.
        $this->breakTable();

        // Tolerant arm: returns the ambiguous null (policy unchanged)…
        self::assertNull(NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED));

        // …but must NOT have cached it. Restore WITHOUT flushing the cache:
        // if the null had been cached, this would still answer null.
        $this->restoreTable();

        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED),
            'a failed read must not be frozen into the cache for the rest of the request'
        );
    }

    public function testASuccessfulReadIsStillCached(): void
    {
        // Anti-vacuity for the test above: caching is not simply disabled.
        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED)
        );

        // Delete the row WITHOUT flushing; the cached answer must survive.
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query("DELETE FROM `" . NftSpamContractRepository::table() . "`");

        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED),
            'a successful read is still cached, so the no-cache rule is specific to failures'
        );
    }

    // ── 3. THE SUPPRESSION POLICY IS UNCHANGED
    // ═══════════════════════════════════════════════════════════════════

    public function testTheTolerantArmStillReturnsNullOnFailureRatherThanThrowing(): void
    {
        // ⚠ Deliberate. Three callers treat null as "not denied", and
        // flipping them to fail-closed would let one transient fault blank
        // a user's collections — a worse failure than the one being fixed.
        // That decision stays with the suppression policy, not with this
        // repository change.
        $this->breakTable();

        $value = null;
        $threw = false;
        try {
            $value = NftSpamContractRepository::getRule(self::CHAIN_ID, self::DENIED);
        } catch (\Throwable $e) {
            $threw = true;
        }

        self::assertFalse($threw, 'getRule() must not start throwing at its existing callers');
        self::assertNull($value);
    }

    public function testAMalformedPairIsAbsentNotUnreadable(): void
    {
        // It runs no query at all, so it cannot be a read failure — even
        // from the fail-closed arm, and even while the table is broken.
        $this->breakTable();

        self::assertNull(NftSpamContractRepository::getRuleOrThrow(0, self::DENIED));
        self::assertNull(NftSpamContractRepository::getRuleOrThrow(self::CHAIN_ID, ''));
    }
}
