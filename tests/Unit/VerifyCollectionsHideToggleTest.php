<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
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

    /**
     * An innocent bystander. Every "the lookup came back wrong" test
     * below puts THIS contract in the returned row, so a handler that
     * takes the first row it is handed instead of the one it asked for
     * writes a permanent DENY rule against a collection nobody touched.
     * The assertions name it explicitly.
     */
    private const OTHER_COLLECTION_ID = 9999;
    private const OTHER_CONTRACT      = 'cosmos1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

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

    /** A row shaped like the admin lookup's, for the wrong collection. */
    private static function otherCollectionRow(): object
    {
        return (object) [
            'id'               => (string) self::OTHER_COLLECTION_ID,
            'chain_id'         => (string) self::CHAIN_ID,
            'contract_address' => self::OTHER_CONTRACT,
            'collection_name'  => 'Somebody Else',
        ];
    }

    /** Nothing was written, anywhere, for either contract. */
    private function assertNothingWasWritten(): void
    {
        self::assertNull(
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT),
            'a lookup that did not answer must not produce a rule'
        );
        self::assertNull(
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::OTHER_CONTRACT),
            'and above all not a rule against the collection nobody asked about'
        );
        self::assertSame('0', $this->deniedFlag(), 'no rule, no cached flag');
        self::assertCount(
            1,
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10),
            'the inventory is untouched'
        );
    }

    // ── THE LOOKUP CONTRACT ─────────────────────────────────────────────

    /**
     * `findManyByIds()` returns `collection id → row`. The handler read
     * `$rows[0]`, which is null for every id the page can emit, so every
     * hide and unhide answered "collection not found" and wrote nothing —
     * for as long as the button existed.
     *
     * The suite did not catch it because the DOUBLE returned a convenient
     * zero-indexed list. This test pins the double to the production
     * shape; if anybody makes it a list again, this fails first and says
     * why, before the handler tests fail for a reason that looks unrelated.
     */
    public function test_the_bulk_lookup_returns_a_map_keyed_by_collection_id(): void
    {
        $rows = CollectionRepository::findManyByIds([self::COLLECTION_ID]);

        self::assertSame(
            [self::COLLECTION_ID],
            array_keys($rows),
            'findManyByIds is keyed by collection id — see CollectionRepository::findManyByIds()'
        );
        self::assertArrayNotHasKey(0, $rows, 'a zero-indexed list is NOT this contract');
        self::assertSame(self::COLLECTION_ID, (int) $rows[self::COLLECTION_ID]->id);
    }

    /**
     * The map came back without the id that was asked for — the shape the
     * defect produced on every single call.
     *
     * The refusal has to survive a NON-EMPTY map: `reset()`,
     * `current()` and `array_values($rows)[0]` all "find" the bystander
     * here and would hide it instead. Only indexing by the requested id
     * is safe.
     */
    public function test_a_map_without_the_requested_id_is_refused_before_any_write(): void
    {
        CollectionRepository::$findManyByIdsOverride = [
            self::OTHER_COLLECTION_ID => self::otherCollectionRow(),
        ];

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        self::assertSame('error', $notices[0]['type']);
        self::assertStringContainsString('collection not found', $notices[0]['message']);
        $this->assertNothingWasWritten();
    }

    /**
     * The key says one collection, the row says another.
     *
     * The real repository keys off the row's own id, so it cannot produce
     * this — which is the point of asserting it anyway. The check costs
     * one integer comparison and is what stops a future re-implementation
     * of the lookup from silently denying the wrong contract.
     */
    public function test_a_row_whose_id_disagrees_with_the_key_is_refused_before_any_write(): void
    {
        CollectionRepository::$findManyByIdsOverride = [
            self::COLLECTION_ID => self::otherCollectionRow(),
        ];

        $notices = $this->post('hide_' . self::COLLECTION_ID);

        self::assertSame('error', $notices[0]['type']);
        self::assertStringContainsString(
            'different collection than the one asked for',
            $notices[0]['message'],
            'a mismatched row is a data-integrity anomaly, not a plain miss — say so'
        );
        $this->assertNothingWasWritten();

        $errors = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn(array $line): bool => $line['level'] === 'error'
        ));
        self::assertNotSame([], $errors, 'a mismatched lookup must leave a trace');
        self::assertSame('verify_collections_hide_id_mismatch', $errors[0]['context']['action'] ?? null);
        self::assertSame(self::COLLECTION_ID, $errors[0]['context']['requested_id'] ?? null);
        self::assertSame(self::OTHER_COLLECTION_ID, $errors[0]['context']['returned_id'] ?? null);
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

    // ── the emit path's own guard ───────────────────────────────────────

    /**
     * DENY POINT 3 still stands on its own.
     *
     * The cached flag is a queue optimisation, not the enforcement. If it
     * goes stale — a failed sync, a row re-inventoried by a sweep, the
     * partial-completion case two tests above — the candidate walks back
     * into the emit queue, and the ONLY thing between it and a
     * user-facing collection row is the live `NftSpamFilter::isSpam()`
     * re-check inside `emitCollections()`.
     *
     * The stale flag is restored deliberately here and the queue is
     * asserted non-empty first, so the "it was denied" conclusion cannot
     * come from an empty queue.
     */
    public function test_the_live_rule_recheck_still_blocks_the_emit_when_the_cached_flag_goes_stale(): void
    {
        $this->post('hide_' . self::COLLECTION_ID);
        self::assertSame(
            NftSpamContractRepository::RULE_DENY,
            NftSpamContractRepository::getRule(self::CHAIN_ID, self::CONTRACT),
            'precondition: the authoritative rule is in force'
        );

        // The cache goes stale behind the rule's back.
        CosmwasmContractRepository::setDenied(self::CHAIN_ID, self::CONTRACT, false);
        self::assertSame('0', $this->deniedFlag());
        self::assertCount(
            1,
            CosmwasmContractRepository::findEmittable(self::CHAIN_ID, 10),
            'POSITIVE CONTROL: a stale flag really does put the candidate back in the emit queue'
        );

        $chain = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($chain);

        $emit = CosmwasmDiscoveryService::emitCollections(
            self::CHAIN_ID,
            new CosmosFetcher($chain),
            new CosmwasmTickBudget(100, 60),
            10
        );

        self::assertSame(0, $emit['emitted'], 'a hidden contract must never become a collection row');
        self::assertSame(1, $emit['denied']);
        self::assertSame([], CollectionRepository::$upserted);
        self::assertSame('1', $this->deniedFlag(), 'and the emit path repairs the cache on its way past');
    }
}
