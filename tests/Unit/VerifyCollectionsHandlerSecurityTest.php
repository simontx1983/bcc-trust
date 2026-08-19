<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-A security contract for Verify Collections.
 *
 * Before this batch ONE nonce — `bcc_verify_collections_nonce` — gated
 * fourteen action branches, so a nonce minted by the read-only "Test CW-721"
 * button also authorised a hard delete, a provider-consuming scanner
 * backfill and a platform-wide hide. These tests pin the isolation:
 *
 *   - every VC-A route has its own nonce action;
 *   - per-row routes bind that nonce to the collection id;
 *   - a VC-A nonce is rejected by the deferred VC-B actions;
 *   - no repository work happens before the nonce is verified;
 *   - every mutation ends in a redirect (PRG) and a durable audit row.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsHandlerSecurityTest extends TestCase
{
    private const CID = 7;
    private const OTHER_CID = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';

        \BccAdminTestState::reset();
        \BccTransientStore::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();

        $_POST = [];
        $_GET  = [];
    }

    // ── Nonce isolation: the core of this batch ─────────────────────────

    /** @return list<array{0: string, 1: string}> */
    public static function vcaRoutes(): array
    {
        return [
            'save'       => [VerifyCollectionsPage::ACTION_SAVE, 'handleSavePost'],
            'provision'  => [VerifyCollectionsPage::ACTION_PROVISION, 'handleProvisionPost'],
            'add'        => [VerifyCollectionsPage::ACTION_ADD, 'handleAddCollectionPost'],
            'add_cosmos' => [VerifyCollectionsPage::ACTION_ADD_COSMOS, 'handleAddCosmosPost'],
        ];
    }

    #[DataProvider('vcaRoutes')]
    public function testEachRouteRejectsTheOldSharedPageNonce(string $action, string $method): void
    {
        // The pre-batch nonce. It used to open all fourteen branches.
        \BccAdminTestState::$validNonceAction = 'bcc_verify_collections_nonce';
        $_POST['collection_id'] = self::CID;

        $this->expectException(\BccAdminDie::class);
        try {
            VerifyCollectionsPage::$method();
        } finally {
            $this->assertNoDomainWork();
        }
    }

    #[DataProvider('vcaRoutes')]
    public function testEachRouteRejectsAnotherVcaRoutesNonce(string $action, string $method): void
    {
        // Hold a nonce for a DIFFERENT VC-A route.
        $other = $action === VerifyCollectionsPage::ACTION_SAVE
            ? VerifyCollectionsPage::ACTION_ADD
            : VerifyCollectionsPage::ACTION_SAVE;
        \BccAdminTestState::$validNonceAction = $other;
        $_POST['collection_id'] = self::CID;

        $this->expectException(\BccAdminDie::class);
        try {
            VerifyCollectionsPage::$method();
        } finally {
            $this->assertNoDomainWork();
        }
    }

    /**
     * A VC-A nonce must not authorise any deferred VC-B action.
     *
     * VC-B still shares the broad page nonce, so the guarantee runs the
     * other way: the VC-B dispatcher only accepts NONCE_KEY, and none of
     * the VC-A nonce actions equals it.
     *
     * @return list<array{0: string}>
     */
    public static function vcbActions(): array
    {
        return [['hide_7'], ['unhide_7'], ['cw_backfill_4'], ['cw_discovery_off_4']];
    }

    #[DataProvider('vcbActions')]
    public function testVcaNoncesAreNotAcceptedByVcbActions(string $vcbAction): void
    {
        // Every VC-A nonce action, checked against the VC-B dispatcher's key.
        foreach ([
            VerifyCollectionsPage::ACTION_SAVE,
            VerifyCollectionsPage::ACTION_PROVISION,
            VerifyCollectionsPage::ACTION_ADD,
            VerifyCollectionsPage::ACTION_ADD_COSMOS,
            VerifyCollectionsPage::ACTION_DELETE . '_' . self::CID,
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::CID,
        ] as $vcaNonce) {
            $this->assertNotSame(
                'bcc_verify_collections_nonce',
                $vcaNonce,
                "VC-A nonce {$vcaNonce} must not equal the VC-B dispatcher key, which is what {$vcbAction} verifies."
            );
        }
    }

    public function testDeleteNonceIsBoundToItsCollection(): void
    {
        $_POST['collection_id'] = self::CID;
        // Nonce minted for a DIFFERENT collection.
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_DELETE . '_' . self::OTHER_CID;

        $this->expectException(\BccAdminDie::class);
        try {
            VerifyCollectionsPage::handleDeletePost();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
        }
    }

    public function testTestQueryNonceIsRejectedByDelete(): void
    {
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::CID;

        $this->expectException(\BccAdminDie::class);
        try {
            VerifyCollectionsPage::handleDeletePost();
        } finally {
            // The old shared nonce made exactly this possible.
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
        }
    }

    // ── Capability ──────────────────────────────────────────────────────

    #[DataProvider('vcaRoutes')]
    public function testEachRouteEnforcesCapabilityItself(string $action, string $method): void
    {
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction = $action;
        $_POST['collection_id'] = self::CID;

        try {
            VerifyCollectionsPage::$method();
            $this->fail('Expected a 403 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNoDomainWork();
        // Capability is checked BEFORE the nonce, so no nonce check ran.
        $this->assertSame([], \BccAdminTestState::$nonceChecks);
    }

    // ── Ordering: no domain work before CSRF validation ─────────────────

    public function testDeleteWithBadNonceNeverReachesTheRepository(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID);
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            VerifyCollectionsPage::handleDeletePost();
        } finally {
            $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
            $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        }
    }

    public function testZeroCollectionIdIsRejectedBeforeAnyNonceIsBuilt(): void
    {
        $_POST['collection_id'] = 0;

        try {
            VerifyCollectionsPage::handleDeletePost();
            $this->fail('Expected a 400 halt.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertSame([], \BccAdminTestState::$nonceChecks);
    }

    // ── Bulk bound ──────────────────────────────────────────────────────

    /** @return list<array{0: int, 1: bool}> */
    public static function bulkSizes(): array
    {
        $max = VerifyCollectionsPage::MAX_BULK_IDS;
        return [
            'boundary-1' => [$max - 1, true],
            'boundary'   => [$max, true],
            'boundary+1' => [$max + 1, false],
        ];
    }

    #[DataProvider('bulkSizes')]
    public function testBulkSaveBound(int $count, bool $shouldWrite): void
    {
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_SAVE;
        $_POST['known'] = range(1, $count);

        try {
            VerifyCollectionsPage::handleSavePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $calls = \BCC\Trust\Onchain\Repositories\CollectionRepository::$bulkCalls;
        if ($shouldWrite) {
            $this->assertCount(1, $calls, 'Within the bound the save must run.');
            $this->assertCount($count, $calls[0]['unverify']);
        } else {
            // Rejected, NOT truncated — a truncating save would report
            // success for rows it never wrote.
            $this->assertSame([], $calls, 'Oversized payload must be rejected.');
        }
    }

    public function testBulkSaveDeduplicatesAndDropsNonPositiveIds(): void
    {
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_SAVE;
        $_POST['known'] = [5, 5, 5, 0, -3, 6, '7'];

        try {
            VerifyCollectionsPage::handleSavePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $calls = \BCC\Trust\Onchain\Repositories\CollectionRepository::$bulkCalls;
        $this->assertSame([5, 6, 7], $calls[0]['unverify']);
    }

    // ── PRG ─────────────────────────────────────────────────────────────

    public function testSaveRedirectsAndCarriesContext(): void
    {
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_SAVE;
        $_POST['known']  = [1];
        $_POST['paged']  = '3';
        $_POST['chain']  = 'cosmos';
        $_POST['vstate'] = 'verified';

        try {
            VerifyCollectionsPage::handleSavePost();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('bcc-verify-collections', $r->args['page']);
            $this->assertSame('3', $r->args['paged']);
            $this->assertSame('cosmos', $r->args['chain']);
            $this->assertSame('verified', $r->args['vstate']);
            $this->assertSame('save', $r->args['bcc_vc_done']);
            // Lands on admin.php, not the admin-post router.
            $this->assertStringContainsString('/wp-admin/admin.php', $r->url);
            $this->assertStringNotContainsString('admin-post.php', $r->url);
        }
    }

    public function testSaveAuditsDurably(): void
    {
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_SAVE;
        $_POST['known'] = [1, 2];
        $_POST['verified'] = [1 => '1'];

        try {
            VerifyCollectionsPage::handleSavePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame(['admin_vc_verification_saved'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('collection', $rows[0]['targetType']);
    }

    // ── Delete protection ───────────────────────────────────────────────

    public function testDeleteIsBlockedWhenALiveCommunityExists(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID, 4, '0xabc');
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups['4|0xabc'] = 99;

        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_DELETE . '_' . self::CID;

        try {
            VerifyCollectionsPage::handleDeletePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
        // No false success row.
        $this->assertNotContains('admin_vc_collection_deleted', \BCC\Trust\Core\Security\AuditLogger::actions());

        $notices = \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
        $this->assertNotSame([], $notices, 'Operator must be told why it was blocked.');
        $this->assertStringContainsString('community', strtolower($notices[0]['message']));
    }

    public function testEligibleCollectionDeletesExactlyOnceAndAudits(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID, 4, '0xabc');

        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_DELETE . '_' . self::CID;

        try {
            VerifyCollectionsPage::handleDeletePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        $this->assertSame([self::CID], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
        $this->assertContains('admin_vc_collection_deleted', \BCC\Trust\Core\Security\AuditLogger::actions());
    }

    // ── Audit target semantics ──────────────────────────────────────────

    public function testEveryVcaAuditActionFitsTheActionColumn(): void
    {
        foreach ([
            'admin_vc_verification_saved',
            'admin_vc_communities_provisioned',
            'admin_vc_manual_collection_added',
            'admin_vc_manual_collection_add_failed',
            'admin_vc_cosmos_collection_upserted',
            'admin_vc_cosmos_collection_add_failed',
            'admin_vc_cosmos_upsert_unresolved',
            'admin_vc_collection_deleted',
            'admin_vc_collection_delete_failed',
            'admin_vc_collection_verified',
            'admin_vc_collection_unverified',
            'admin_vc_community_provisioned',
            'admin_vc_community_provision_failed',
            'admin_vc_testquery_failed',
        ] as $action) {
            $this->assertLessThanOrEqual(50, strlen($action), $action . ' exceeds the action column width.');
            $this->assertStringStartsWith('admin_vc_', $action);
        }
    }

    public function testAChainIdIsNeverStoredAsACollectionTarget(): void
    {
        // Delete a collection whose id (7) differs from its chain id (4): if
        // any handler confused the two, the target would read 4.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID, 4, '0xabc');
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_DELETE . '_' . self::CID;

        try {
            VerifyCollectionsPage::handleDeletePost();
        } catch (\BccAdminRedirect) {
            // expected
        }

        foreach (\BCC\Trust\Core\Security\AuditLogger::$rows as $row) {
            if ($row['targetType'] === 'collection') {
                $this->assertSame(
                    self::CID,
                    $row['targetId'],
                    'A collection target must carry the collection id, never the chain id.'
                );
            }
        }
    }

    // ── Test CW-721 failure policy ──────────────────────────────────────

    /**
     * Case 1 of the declared policy: an EXPECTED negative probe result —
     * here the collection's chain cannot be resolved — produces an operator
     * notice and NO durable row. Nothing changed, so recording that someone
     * looked at a contract would be pure noise.
     *
     * Case 2 (an unexpected exception escaping the probe) routes through
     * AdminActionSupport::failure() under the declared name
     * `admin_vc_testquery_failed`. That helper's durable-row-plus-
     * correlation-ID behaviour is pinned by OnchainAdminActionSupportTest
     * (Batch 1), so it is not re-proven here with a synthetic fault.
     */
    public function testTestQueryExpectedNegativeWritesNoDurableRow(): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID, 4, '0xabc');
        // Chain deliberately not seeded → the probe reports a negative result.
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::CID;

        try {
            VerifyCollectionsPage::handleTestQueryPost();
            $this->fail('Expected PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame('testquery', $r->args['bcc_vc_done']);
        }

        $this->assertSame(
            [],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'A read-only probe with an expected negative result must not write a durable row.'
        );

        // The operator is still told something.
        $notices = \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
        $this->assertNotSame([], $notices);
    }

    /**
     * Case 2 of the declared policy, proven through the real handler rather
     * than inferred from AdminActionSupport in isolation: an UNEXPECTED
     * exception escaping the probe is a fault in our code or transport, not
     * a verdict about the contract. It must be traceable, and the trace must
     * not leak provider internals to the browser.
     *
     * The fault is injected with the fake's explicit `$throws` switch. A test
     * that provoked it by feeding the probe a malformed fixture would prove
     * only that the fixture was rejected.
     */
    public function testTestQueryUnexpectedExceptionIsAuditedOnceAndRedacted(): void
    {
        $secret = 'Test provider failure SECRET_INTERNAL_DETAIL';

        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::reset();
        \BCC\Trust\Onchain\Factories\FetcherFactory::reset();

        \BCC\Trust\Onchain\Repositories\CollectionRepository::seed(self::CID, 4, 'cosmos1probe');
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'cosmos', 'cosmos');
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$throws = new \RuntimeException($secret);

        // ── The gates run BEFORE the provider is reachable ───────────────
        // Missing capability: refused with the probe untouched.
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::CID;
        try {
            VerifyCollectionsPage::handleTestQueryPost();
            $this->fail('A request without manage_options must be refused.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }
        \BccAdminTestState::$can = true;

        // A nonce scoped to a DIFFERENT collection: refused, probe untouched.
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::OTHER_CID;
        try {
            VerifyCollectionsPage::handleTestQueryPost();
            $this->fail('A nonce scoped to another collection must be refused.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertSame(
            [],
            \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$probes,
            'No outbound provider call may happen before capability and nonce both pass.'
        );
        $this->assertSame(
            [],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'A refused request is not an authorized operation and writes no durable row.'
        );

        // ── The authorized request, which faults inside the probe ────────
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_TESTQUERY . '_' . self::CID;

        $redirect = null;
        try {
            VerifyCollectionsPage::handleTestQueryPost();
            $this->fail('The handler must terminate in a PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $redirect = $r;
        }

        // The probe ran exactly once, against the collection under test.
        $this->assertSame(
            ['cosmos1probe'],
            \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$probes,
            'The probe must be invoked exactly once for one authorized click.'
        );

        // ── The operator sees a correlation ID, never the provider text ──
        $notices = \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
        $this->assertCount(1, $notices);
        $this->assertSame('error', $notices[0]['type']);

        $operatorText = $notices[0]['message'] . ' ' . implode(' ', array_map('strval', $redirect->args));
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $operatorText);
        $this->assertStringNotContainsString($secret, $operatorText);
        $this->assertStringNotContainsString('RuntimeException', $operatorText);

        $this->assertSame(
            1,
            preg_match('/\bbcc-[0-9a-f]{8}\b/', (string) $notices[0]['message'], $m),
            'The operator notice must carry a correlation ID to hand to an engineer.'
        );
        $correlationId = $m[0];

        // ── The full exception is in the technical log, not the browser ──
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame($secret, $errors[0]['context']['message']);
        $this->assertSame(\RuntimeException::class, $errors[0]['context']['exception']);
        $this->assertSame($correlationId, $errors[0]['context']['correlation_id']);
        $this->assertSame('collection', $errors[0]['context']['target_type']);
        $this->assertSame(self::CID, $errors[0]['context']['target_id']);

        // ── Exactly one durable event, correctly targeted, no success ────
        $this->assertSame(
            ['admin_vc_testquery_failed'],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'One crashed authorized operation writes exactly one row, and no success row.'
        );
        $row = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        $this->assertSame('collection', $row['targetType']);
        $this->assertSame(self::CID, $row['targetId']);

        // ── PRG: the destination is inert, so a refresh cannot re-probe ──
        $this->assertSame('testquery', $redirect->args['bcc_vc_done']);
        foreach (['action', '_wpnonce', 'collection_id'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $redirect->args,
                "The redirect destination must not carry {$key}, or a refresh replays the probe."
            );
        }
        $this->assertCount(
            1,
            \BCC\Trust\Onchain\Fetchers\CosmosFetcher::$probes,
            'Reaching the redirect destination must not invoke the provider again.'
        );
    }

    /**
     * The Cosmos add write reported rows, but the row cannot be read back
     * through the table's uniqueness key. The handler must not fabricate a
     * success record against a target it cannot name — and, above all, must
     * not park the CHAIN id in a `collection` target, which would be
     * indistinguishable from a real collection id in any later forensic query.
     */
    public function testCosmosUnresolvedUpsertAuditsTheChainAndWarnsTheOperator(): void
    {
        $chainId  = 9;
        $contract = 'cosmos1unresolved';

        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Fetchers\CosmosFetcher::reset();
        \BCC\Trust\Onchain\Factories\FetcherFactory::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed($chainId, 'cosmos', 'cosmos');

        // The write reports success…
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertWritten = 1;
        // …but the row is not readable back afterwards.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$findByChainContractReturnsNull = true;

        $_POST['bcc_vc_add_chain_id'] = $chainId;
        $_POST['bcc_vc_add_contract'] = $contract;
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_ADD_COSMOS;

        $redirect = null;
        try {
            VerifyCollectionsPage::handleAddCosmosPost();
            $this->fail('The handler must terminate in a PRG redirect.');
        } catch (\BccAdminRedirect $r) {
            $redirect = $r;
        }

        // ── The write was attempted exactly once ────────────────────────
        $calls = \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertCalls;
        $this->assertCount(1, $calls, 'An unresolvable read-back must not trigger a retry write.');
        $this->assertSame($contract, $calls[0]['rows'][0]['contract_address']);
        $this->assertSame($chainId, $calls[0]['rows'][0]['chain_id']);

        // ── Exactly one event, and it is the honest one ─────────────────
        $this->assertSame(
            ['admin_vc_cosmos_upsert_unresolved'],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'No success event may be claimed for a row that could not be resolved.'
        );
        $row = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        $this->assertSame('chain', $row['targetType']);
        $this->assertSame($chainId, $row['targetId']);

        // ── A chain id must never be stored as a collection target ──────
        foreach (\BCC\Trust\Core\Security\AuditLogger::$rows as $r) {
            $this->assertNotSame(
                'collection',
                $r['targetType'],
                'No collection target may be written when no collection id is known.'
            );
        }

        // ── The inconsistency is recorded for an engineer ───────────────
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame($chainId, $errors[0]['context']['chain_id']);
        $this->assertSame($contract, $errors[0]['context']['contract']);
        $this->assertSame(1, $errors[0]['context']['written']);

        // ── The operator is warned, not congratulated ───────────────────
        $notices = \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
        $this->assertCount(1, $notices);
        $this->assertSame('warning', $notices[0]['type']);
        $this->assertNotSame('success', $notices[0]['type']);
        $this->assertStringContainsString($contract, (string) $notices[0]['message']);

        // ── PRG: the destination cannot replay the write ────────────────
        $this->assertSame('add_cosmos', $redirect->args['bcc_vc_done']);
        foreach (['action', '_wpnonce', 'bcc_vc_add_contract', 'bcc_vc_add_chain_id'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $redirect->args,
                "The redirect destination must not carry {$key}, or a refresh replays the upsert."
            );
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function assertNoDomainWork(): void
    {
        $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$bulkCalls);
        $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$deleted);
        $this->assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$added);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }
}
