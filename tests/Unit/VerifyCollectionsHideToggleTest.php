<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The operator Hide / Unhide button, END TO END through the real handler.
 *
 * ── WHY IT GOES THROUGH `handlePost()` ──────────────────────────────────
 * The bug this pins was invisible for exactly one reason: the only tests
 * of DENY POINT 4 called `CosmwasmDiscoveryService::syncDenyFlags()`
 * DIRECTLY. That proves the mechanism works. It cannot prove anybody
 * calls it — and nobody did. The hide button wrote the rule and returned,
 * the scanner's cached `denied` flag stayed stale, and a green suite said
 * everything was fine.
 *
 * So every test below posts the SAME `$_POST` a browser posts, through
 * the SAME dispatcher, and asserts on the notices the operator actually
 * sees plus the state the scanner is actually left in. If the production
 * call is removed, these fail; if `syncDenyFlags()` is called directly
 * from a test again, that test proves nothing and this one still fails.
 *
 * Isolation: the cosmwasm-discovery stubs — every repository faked at its
 * production FQN inside a subprocess, no database, no network. The real
 * classes under test are VerifyCollectionsPage, CosmwasmDiscoveryService
 * and NftSpamFilter.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsHideToggleTest extends TestCase
{
    private const CHAIN_ID      = 8;
    private const COLLECTION_ID = 4242;
    private const CONTRACT      = 'cosmos1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        \BCC\Core\Log\Logger::reset();

        ChainRepository::seed(self::CHAIN_ID, 'cosmos');
        CollectionRepository::seedAdminRow(self::COLLECTION_ID, self::CHAIN_ID, self::CONTRACT, 'Fixture Collection');

        // The scanner has inventoried this contract and classified it as a
        // CW-721 candidate awaiting emit — the state a hide is meant to act on.
        CosmwasmContractRepository::seed(
            self::CHAIN_ID,
            self::CONTRACT,
            CosmwasmClassifier::CONFIRMED,
            434
        );

        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        parent::tearDown();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /**
     * Post one admin action through the real dispatcher.
     *
     * @return list<array{type: string, message: string}>
     */
    private function post(string $action, string $nonce = 'test-nonce'): array
    {
        $_POST = [
            'bcc_vc_action'             => $action,
            VerifyCollectionsPage::NONCE_NAME => $nonce,
        ];

        $handler = new ReflectionMethod(VerifyCollectionsPage::class, 'handlePost');
        $handler->setAccessible(true);

        /** @var list<array{type: string, message: string}> $notices */
        $notices = $handler->invoke(null);

        return $notices;
    }

    private function deniedFlag(): ?string
    {
        $row = CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT);

        return $row === null ? null : (string) $row->denied;
    }

    /** @param list<array{type: string, message: string}> $notices */
    private function joined(array $notices): string
    {
        return implode(' ', array_column($notices, 'message'));
    }

    // ── HIDE ────────────────────────────────────────────────────────────

    public function test_hide_writes_the_rule_and_synchronises_the_scanner_inventory(): void
    {
        self::assertSame('0', $this->deniedFlag(), 'precondition: the candidate is visible');
        self::assertCount(
            1,
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10),
            'precondition: the candidate is in the emit queue'
        );

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        // 1. the authoritative rule
        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT)
        );

        // 2. THE WIRING: the cached flag followed the rule without anybody
        //    calling syncDenyFlags() from the test.
        self::assertSame('1', $this->deniedFlag(), 'the hide never reached the scanner inventory');

        // 3. and the indexed queue predicate (`denied = 0`) now skips it
        self::assertSame(
            [],
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10),
            'a hidden candidate must drop out of the emit queue, not be re-filtered on every sweep'
        );

        self::assertCount(1, $notices);
        self::assertSame('success', $notices[0]['type']);
        self::assertStringContainsString('Hid "Fixture Collection" from users', $notices[0]['message']);
    }

    public function test_unhide_clears_the_flag_and_returns_the_candidate_to_the_queue(): void
    {
        $this->post('hide_' . self::COLLECTION_ID);
        self::assertSame('1', $this->deniedFlag(), 'precondition: hidden');

        $notices = $this->post('unhide_' . self::COLLECTION_ID);

        self::assertSame(
            NftSpamContractRepository::RULE_ALLOW,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT)
        );
        self::assertSame('0', $this->deniedFlag(), 'unhide must genuinely permit later discovery');
        self::assertCount(
            1,
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10),
            'the candidate is back in the emit queue'
        );

        self::assertSame('success', $notices[0]['type']);
        self::assertStringContainsString('Restored "Fixture Collection" for users', $notices[0]['message']);
    }

    /**
     * A contract the scanner has never inventoried (a manual add, a
     * wallet-link discovery, a non-Cosmos chain) has nothing to sync, and
     * that is a plain success — not a warning about a cache that does not
     * exist.
     */
    public function test_hiding_a_collection_the_scanner_never_saw_is_a_plain_success(): void
    {
        CosmwasmContractRepository::reset();

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        self::assertSame('success', $notices[0]['type']);
        self::assertNull(CosmwasmContractRepository::find(self::CHAIN_ID, self::CONTRACT));
    }

    // ── the rule write FAILS ────────────────────────────────────────────

    /**
     * A rule that did not land must not move the cached flag. Suppressing
     * a contract on the strength of a rule that does not exist is the
     * mirror image of the bug being fixed.
     */
    public function test_a_failed_rule_write_leaves_the_cached_flag_untouched(): void
    {
        // An unknown rule string is rejected by addRule() → false.
        $collectionId = self::COLLECTION_ID;
        $handler      = new ReflectionMethod(VerifyCollectionsPage::class, 'handleHideToggle');
        $handler->setAccessible(true);

        NftSpamContractRepository::$rejectWrites = true;

        /** @var list<array{type: string, message: string}> $notices */
        $notices = $handler->invoke(null, $collectionId, true);

        self::assertSame('error', $notices[0]['type']);
        self::assertStringContainsString('rule write failed', $notices[0]['message']);
        self::assertSame('0', $this->deniedFlag(), 'no rule, no suppression');
        self::assertNull(NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT));
    }

    // ── the rule write SUCCEEDS, the cache sync does NOT ────────────────

    /**
     * THE PARTIAL-COMPLETION CASE, failure mode 1: the flag cannot be
     * read back, so we do not know whether it landed.
     *
     * The operator must not be told "Hid X" full stop — that claims a
     * finished job. They are told what IS true (the rule is written and in
     * force) and what is NOT (the scanner's cached flag and the count
     * derived from it are stale).
     */
    public function test_a_sync_that_cannot_be_confirmed_reports_partial_completion(): void
    {
        CosmwasmContractRepository::$failReads = ['deniedFlag'];

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        self::assertCount(1, $notices);
        self::assertSame('warning', $notices[0]['type'], 'a partially-completed action is not a success');

        $message = $notices[0]['message'];
        // what DID happen
        self::assertStringContainsString('rule was written and IS in force', $message);
        // what did NOT
        self::assertStringContainsString('cached flag for this contract could not be updated', $message);
        self::assertStringContainsString('"hidden by a rule" count on this page is stale', $message);
        // and why it is not an exposure
        self::assertStringContainsString('re-checks the live rule on every pass', $message);

        // The rule really is in force despite the warning.
        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT)
        );
    }

    /**
     * Failure mode 2, the quieter one: the read works, the WRITE silently
     * did not stick. `syncDenyFlags()` returns a row count and reports
     * success either way, which is exactly why the handler reads the flag
     * back instead of trusting it.
     */
    public function test_a_deny_flag_that_never_moved_reports_partial_completion(): void
    {
        CosmwasmContractRepository::$swallowSetDenied = true;

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        self::assertSame('warning', $notices[0]['type']);
        self::assertStringContainsString('could not be updated', $notices[0]['message']);
        self::assertSame('0', $this->deniedFlag(), 'the flag genuinely did not move — that is the point');
    }

    public function test_the_partial_failure_is_logged_with_the_failing_method(): void
    {
        CosmwasmContractRepository::$failReads = ['deniedFlag'];

        $this->post('hide_' . self::COLLECTION_ID);

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn(array $line): bool => $line['level'] === 'error'
        ));

        self::assertNotSame([], $errors, 'a sync that could not be confirmed must leave a trace');
        self::assertSame('deniedFlag', $errors[0]['context']['method'] ?? null);
    }

    // ── the dispatcher itself ───────────────────────────────────────────

    public function test_a_bad_nonce_never_reaches_the_rule_or_the_scanner(): void
    {
        $notices = $this->post('hide_' . self::COLLECTION_ID, 'not-the-nonce');

        self::assertStringContainsString('Security check failed', $this->joined($notices));
        self::assertNull(NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT));
        self::assertSame('0', $this->deniedFlag());
    }

    public function test_an_unknown_collection_id_is_refused_before_any_write(): void
    {
        $notices = $this->post('hide_999999');

        self::assertSame('error', $notices[0]['type']);
        self::assertStringContainsString('collection not found', $notices[0]['message']);
        self::assertSame('0', $this->deniedFlag());
    }
}
