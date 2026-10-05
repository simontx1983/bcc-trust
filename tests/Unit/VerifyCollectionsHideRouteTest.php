<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B1 TRANSPORT contract for Hide / Unhide.
 *
 * Before this batch both directions were branches inside handlePost(),
 * reached after one `bcc_verify_collections_nonce` check shared with the
 * six CosmWasm scanner controls — so a nonce minted to hide a scam
 * contract also authorised a provider-consuming backfill on any chain.
 * Neither had PRG, so a refresh re-applied them, and neither wrote a
 * durable audit row.
 *
 * The DOMAIN half (authoritative rule first, scanner cache second,
 * read-back, partial completion) is pinned by
 * VerifyCollectionsHideToggleTest against the CosmWasm stub family. This
 * file pins the request boundary: capability, method, direction- and
 * target-scoped nonces, PRG, replay, and the durable record.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsHideRouteTest extends TestCase
{
    private const CID       = 7;
    private const OTHER_CID = 8;
    private const CHAIN_ID  = 4;
    private const CONTRACT  = 'cosmos1hideme';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-stubs.php';

        \BccAdminTestState::reset();
        \BccTransientStore::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        CollectionRepository::reset();
        NftSpamContractRepository::reset();
        CosmwasmContractRepository::reset();
        CosmwasmDiscoveryService::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        CollectionRepository::seed(self::CID, self::CHAIN_ID, self::CONTRACT);
    }

    /** Arrange a well-formed request for one direction. */
    private function arrange(bool $hide, int $collectionId = self::CID): string
    {
        $route = $hide ? VerifyCollectionsPage::ACTION_HIDE : VerifyCollectionsPage::ACTION_UNHIDE;

        $_POST['collection_id'] = $collectionId;
        \BccAdminTestState::$validNonceAction = $route . '_' . $collectionId;

        return $route;
    }

    private function invoke(bool $hide): \BccAdminRedirect
    {
        try {
            $hide
                ? VerifyCollectionsPage::handleHidePost()
                : VerifyCollectionsPage::handleUnhidePost();
        } catch (\BccAdminRedirect $r) {
            return $r;
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    /** @return list<array{type: string, message: string}> */
    private function notices(): array
    {
        return \BccTransientStore::$data['bcc_vc_notices_' . \BccAdminTestState::$userId] ?? [];
    }

    private function assertNothingHappened(string $why): void
    {
        $this->assertSame([], NftSpamContractRepository::$added, $why . ' — no rule may be written');
        $this->assertSame([], CosmwasmDiscoveryService::$syncCalls, $why . ' — the scanner must not be touched');
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), $why . ' — no durable row');
    }

    // ── 1. Capability, enforced inside each handler ─────────────────────

    /** @return list<array{0: bool}> */
    public static function directions(): array
    {
        return ['hide' => [true], 'unhide' => [false]];
    }

    #[DataProvider('directions')]
    public function testCapabilityIsEnforcedInsideTheHandler(bool $hide): void
    {
        $this->arrange($hide);
        \BccAdminTestState::$can = false;

        try {
            $hide ? VerifyCollectionsPage::handleHidePost() : VerifyCollectionsPage::handleUnhidePost();
            $this->fail('A request without manage_options must be refused.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertNothingHappened('missing capability');
    }

    // ── 2. POST only ────────────────────────────────────────────────────

    #[DataProvider('directions')]
    public function testGetIsRefused(bool $hide): void
    {
        $this->arrange($hide);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $hide ? VerifyCollectionsPage::handleHidePost() : VerifyCollectionsPage::handleUnhidePost();
            $this->fail('admin-post.php dispatches GET too; the handler must refuse it.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(405, $e->status);
        }

        $this->assertNothingHappened('GET request');
    }

    // ── 3 & 4. Nonce isolation: direction AND target ────────────────────

    public function testAHideNonceCannotDriveUnhide(): void
    {
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_HIDE . '_' . self::CID;

        $this->expectException(\BccAdminDie::class);
        VerifyCollectionsPage::handleUnhidePost();
    }

    public function testAnUnhideNonceCannotDriveHide(): void
    {
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::ACTION_UNHIDE . '_' . self::CID;

        $this->expectException(\BccAdminDie::class);
        VerifyCollectionsPage::handleHidePost();
    }

    #[DataProvider('directions')]
    public function testANonceForAnotherCollectionIsRejected(bool $hide): void
    {
        $route = $hide ? VerifyCollectionsPage::ACTION_HIDE : VerifyCollectionsPage::ACTION_UNHIDE;

        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction = $route . '_' . self::OTHER_CID;

        try {
            $hide ? VerifyCollectionsPage::handleHidePost() : VerifyCollectionsPage::handleUnhidePost();
            $this->fail('A nonce scoped to another collection must be refused.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertNothingHappened('cross-target nonce');
    }

    /** The old broad page nonce must not open either route. */
    #[DataProvider('directions')]
    public function testTheLegacySharedPageNonceIsRejected(bool $hide): void
    {
        $_POST['collection_id'] = self::CID;
        \BccAdminTestState::$validNonceAction = 'bcc_verify_collections_nonce';

        try {
            $hide ? VerifyCollectionsPage::handleHidePost() : VerifyCollectionsPage::handleUnhidePost();
            $this->fail('The shared VC-B nonce must not authorise Hide/Unhide any more.');
        } catch (\BccAdminDie $e) {
            // expected
        }

        $this->assertNothingHappened('legacy shared nonce');
    }

    // ── 5. Rejected before any repository work ──────────────────────────

    public function testAnInvalidCollectionIdIsRefusedBeforeTheNonceIsEvenDerived(): void
    {
        $_POST['collection_id'] = 0;
        \BccAdminTestState::$validNonceAction = VerifyCollectionsPage::ACTION_HIDE . '_0';

        try {
            VerifyCollectionsPage::handleHidePost();
            $this->fail('A non-positive collection id must be refused.');
        } catch (\BccAdminDie $e) {
            $this->assertSame(400, $e->status);
        }

        $this->assertNothingHappened('invalid target');
    }

    // ── 6 & 7. PRG, inert destination, no replay ────────────────────────

    #[DataProvider('directions')]
    public function testPrgRedirectCarriesNoMutationInput(bool $hide): void
    {
        $this->arrange($hide);
        $_POST['paged'] = '3';
        $_POST['chain'] = 'cosmos';

        $r = $this->invoke($hide);

        $this->assertSame($hide ? 'hide' : 'unhide', $r->args['bcc_vc_done']);

        foreach (['action', '_wpnonce', 'collection_id', 'contract', 'bcc_vc_action'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $r->args,
                "The redirect destination must not carry {$key}, or a refresh replays the mutation."
            );
        }

        // List context IS preserved — that is not mutation input.
        $this->assertSame('3', $r->args['paged']);
        $this->assertSame('cosmos', $r->args['chain']);

        // One click, one rule write.
        $this->assertCount(1, NftSpamContractRepository::$added);
    }

    // ── 8, 9, 10. Rule first; scanner second; never the other way ───────

    /**
     * ⚠ THE SCANNER IS NEVER TOUCHED AT ALL (S3).
     *
     * This used to assert an ORDER — rule first, cache second — because both
     * happened. Only the rule happens now, so the assertion that carries the
     * change is that the scanner service is not called even once.
     */
    public function testTheRuleIsWrittenAndTheScannerIsNeverTouched(): void
    {
        $this->arrange(true);

        $this->invoke(true);

        $this->assertCount(1, NftSpamContractRepository::$added, 'the rule is written');
        $this->assertSame(
            [],
            CosmwasmDiscoveryService::$syncCalls,
            'S3: the hide route must not call the scanner deny-flag sync at all'
        );
        $this->assertSame(
            ['admin_vc_hide_applied'],
            \BCC\Trust\Core\Security\AuditLogger::actions(),
            'and the durable row is the plain applied event'
        );
    }

    // ⚠ TWO CASES DELETED BY S3, DELIBERATELY AND NAMED SO THE GAP IS VISIBLE:
    //
    //   testAnUnconfirmedSyncAuditsPartialAndNeverFullSuccess
    //   testAnUnconfirmedUnhideSyncUsesItsOwnEventName
    //
    // Both asserted the `*_sync_unconfirmed` events, which can no longer be
    // emitted because the cache push they described is gone. The names remain
    // valid in historical rows — see the vocabulary test, which still holds
    // them to the column width for exactly that reason.

    public function testUnhideWritesTheAllowRule(): void
    {
        $this->arrange(false);
        $this->invoke(false);

        $this->assertSame(NftSpamContractRepository::RULE_ALLOW, NftSpamContractRepository::$added[0]['rule']);
    }

    /**
     * A returned write failure is a DURABLE failure, not a validation slip.
     *
     * Capability, method, target and nonce all passed: an authorised
     * operator asked for a state change and the authoritative write refused
     * it. "Nothing changed" is precisely the fact worth reconstructing
     * later, so it gets exactly one row — and it must not be mistaken for
     * an applied or partially-applied change.
     */
    #[DataProvider('directions')]
    public function testAFailedRuleWriteIsAuditedAndNeverTouchesTheScannerCache(bool $hide): void
    {
        $this->arrange($hide);
        NftSpamContractRepository::$addResult = false;

        $r = $this->invoke($hide);

        // 1 & 2. The authoritative repository was called exactly once, and failed.
        $this->assertCount(1, NftSpamContractRepository::$added);
        $this->assertSame(
            $hide ? NftSpamContractRepository::RULE_DENY : NftSpamContractRepository::RULE_ALLOW,
            NftSpamContractRepository::$added[0]['rule']
        );

        // 3. The downstream cache is never touched on the strength of a
        //    rule that does not exist.
        $this->assertSame([], CosmwasmDiscoveryService::$syncCalls);
        $this->assertSame(0, CosmwasmContractRepository::$reads);

        // 4, 5 & 6. Exactly one *_failed row, on the real collection, and
        //    nothing claiming the change landed.
        $expected = $hide ? 'admin_vc_hide_failed' : 'admin_vc_unhide_failed';
        $this->assertSame([$expected], \BCC\Trust\Core\Security\AuditLogger::actions());

        $row = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        $this->assertSame('collection', $row['targetType']);
        $this->assertSame(self::CID, $row['targetId']);
        $this->assertNotSame(self::CHAIN_ID, $row['targetId']);

        // 7. The operator is told it failed, with nothing internal in it.
        $notice = $this->notices()[0];
        $this->assertSame('error', $notice['type']);
        foreach (['SQLSTATE', 'wpdb', 'Exception', 'stack', 'SELECT', 'INSERT', self::CONTRACT] as $leak) {
            $this->assertStringNotContainsString($leak, $notice['message']);
        }

        // A correlation ID is NOT minted here: no exception was captured, so
        // promising an engineer a stack trace would be a lie.
        $this->assertSame(0, preg_match('/\bbcc-[0-9a-f]{8}\b/', (string) $notice['message']));

        // The cause is in the technical log instead.
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame(self::CID, $errors[0]['context']['collection_id']);

        // 8. PRG still happens, to an inert destination.
        $this->assertSame($hide ? 'hide' : 'unhide', $r->args['bcc_vc_done']);
        foreach (['action', '_wpnonce', 'collection_id'] as $key) {
            $this->assertArrayNotHasKey($key, $r->args);
        }
    }

    public function testAnUnknownCollectionIsRefusedAfterTheNonceAndWritesNothing(): void
    {
        CollectionRepository::reset();
        $this->arrange(true);

        $this->invoke(true);

        $this->assertNothingHappened('unknown collection');
        $this->assertStringContainsString('not found', $this->notices()[0]['message']);
    }

    // ── 11, 12, 13. The durable record ──────────────────────────────────

    public function testAConfirmedHideAuditsExactlyOnceAgainstTheCollection(): void
    {
        $this->arrange(true);
        CosmwasmContractRepository::$flag = true; // cache agrees with the rule

        $this->invoke(true);

        $this->assertSame(['admin_vc_hide_applied'], \BCC\Trust\Core\Security\AuditLogger::actions());

        $row = \BCC\Trust\Core\Security\AuditLogger::$rows[0];
        $this->assertSame('collection', $row['targetType']);
        $this->assertSame(self::CID, $row['targetId']);
        $this->assertNotSame(self::CHAIN_ID, $row['targetId'], 'a chain id must never be a collection target');

        $this->assertSame('success', $this->notices()[0]['type']);
    }

    public function testAConfirmedUnhideAuditsExactlyOnce(): void
    {
        $this->arrange(false);
        CosmwasmContractRepository::$flag = false;

        $this->invoke(false);

        $this->assertSame(['admin_vc_unhide_applied'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame(self::CID, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetId']);
    }

    public function testACollectionTheScannerNeverSawStillCountsAsApplied(): void
    {
        $this->arrange(true);
        CosmwasmContractRepository::$flag = null; // never inventoried

        $this->invoke(true);

        $this->assertSame(['admin_vc_hide_applied'], \BCC\Trust\Core\Security\AuditLogger::actions());
        $this->assertSame('success', $this->notices()[0]['type']);
    }



    // ── 14. Unexpected exception: redacted, correlated, audited once ────

    public function testAnUnexpectedExceptionIsRedactedAndCorrelated(): void
    {
        $secret = 'Hide provider failure SECRET_INTERNAL_DETAIL';

        $this->arrange(true);
        // ⚠ DRIVEN FROM THE AUTHORITATIVE WRITE NOW. The scanner-cache sync this
        // used to throw from was removed in S3; the property under test — an
        // unexpected exception is redacted, correlated and audited once — is
        // unchanged and still worth covering.
        NftSpamContractRepository::$addThrows = new \RuntimeException($secret);

        $r = $this->invoke(true);

        $notice = $this->notices()[0];
        $operatorText = $notice['message'] . ' ' . implode(' ', array_map('strval', $r->args));
        $this->assertStringNotContainsString('SECRET_INTERNAL_DETAIL', $operatorText);
        $this->assertStringNotContainsString('RuntimeException', $operatorText);

        $this->assertSame(1, preg_match('/\bbcc-[0-9a-f]{8}\b/', (string) $notice['message'], $m));
        $correlationId = $m[0];

        $this->assertSame(
            ['admin_vc_hide_failed'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
        $this->assertSame('collection', \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetType']);
        $this->assertSame(self::CID, \BCC\Trust\Core\Security\AuditLogger::$rows[0]['targetId']);

        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertSame($secret, $errors[0]['context']['message']);
        $this->assertSame(
            $correlationId,
            $errors[0]['context']['correlation_id'],
            'the id the operator is given must be the id an engineer can grep'
        );

        // Still PRG.
        $this->assertSame('hide', $r->args['bcc_vc_done']);
    }

    // ── 20. Vocabulary fits the column ──────────────────────────────────

    public function testEveryVcb1AuditActionFitsTheActionColumn(): void
    {
        // Emitted today.
        $live = [
            'admin_vc_hide_applied',
            'admin_vc_unhide_applied',
            'admin_vc_hide_failed',
            'admin_vc_unhide_failed',
        ];

        // ⚠ RETIRED BY S3, STILL VALID HISTORY. No longer emittable, because the
        // scanner-cache push they described is gone — but existing rows keep them
        // and keep their meaning, and a forensic reader reads them back out of
        // the same column. So they stay in the width check.
        $retired = [
            'admin_vc_hide_sync_unconfirmed',
            'admin_vc_unhide_sync_unconfirmed',
        ];

        $actions = array_merge($live, $retired);

        foreach ($actions as $action) {
            $this->assertLessThanOrEqual(
                50,
                strlen($action),
                "`{$action}` would be truncated by the VARCHAR(50) action column, merging two distinct events."
            );
        }
    }

    // ══ S3: the Hide path names no scanner cache at all ════════════════

    /**
     * ⚠⚠ STATIC DECOUPLING, NOT JUST BEHAVIOURAL.
     *
     * The behavioural tests above show the sync is not CALLED. This shows the
     * handler cannot call it, because the page does not name it — which is what
     * stops it being reintroduced by a later edit that looks locally reasonable.
     *
     * ⚠ TOKEN-WALKED, NOT GREPPED. This file is full of `{@see Foo::bar()}`
     * docblock references, including several that deliberately EXPLAIN the
     * retired cache — a text search would report those as violations and this
     * test would fail on its own documentation. Comments are not code.
     */
    public function testTheHidePathNamesNoScannerCacheInExecutableCode(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/VerifyCollectionsPage.php';
        $src  = (string) file_get_contents($path);

        // Anti-vacuity on the fixture: the file must be the one we think it is.
        $this->assertStringContainsString(
            'handleHideToggle',
            $src,
            'precondition: this is the page that owns Hide'
        );

        // Anti-vacuity on the METHOD: at least one forbidden name IS present as
        // prose, because the docblock explains which cache was retired. A
        // grep-based version of this test would therefore have failed here.
        $this->assertStringContainsString(
            'countDenied',
            $src,
            'the docblock still names the retired cache readers, which is why this walks tokens'
        );

        $forbidden = [
            'CosmwasmDiscoveryService',
            'syncDenyFlags',
            'deniedFlag',
            'findEmittable',
            'countDenied',
        ];

        foreach ($forbidden as $name) {
            $this->assertSame(
                0,
                self::executableReferences($src, $name),
                $name . ' must not appear in executable code on the Hide page (S3)'
            );
        }
    }

    /**
     * PLANTED POSITIVE. The walk must be able to SEE an executable reference,
     * or the four assertions above are worthless.
     */
    public function testTheDecouplingWalkDetectsAPlantedExecutableReference(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/VerifyCollectionsPage.php';
        $src  = (string) file_get_contents($path);

        $this->assertSame(0, self::executableReferences($src, 'syncDenyFlags'));

        // Planted as CODE in a fresh PHP block, not as a comment.
        $planted = $src . '<?php CosmwasmDiscoveryService::syncDenyFlags(1, []); ';

        $this->assertGreaterThan(
            0,
            self::executableReferences($planted, 'syncDenyFlags'),
            'the walk must detect an executable reference when one exists'
        );
        $this->assertGreaterThan(
            0,
            self::executableReferences($planted, 'CosmwasmDiscoveryService'),
            'and the class name too'
        );
    }

    /**
     * PLANTED NEGATIVE. The same name inside a docblock must NOT count, or the
     * test would forbid documenting the retirement.
     */
    public function testTheDecouplingWalkIgnoresADocblockReference(): void
    {
        $commented = '<?php /** {@see CosmwasmDiscoveryService::syncDenyFlags()} */ $x = 1;';

        $this->assertSame(
            0,
            self::executableReferences($commented, 'syncDenyFlags'),
            'a docblock mention is documentation, not a dependency'
        );
    }

    /** Occurrences of a name in EXECUTABLE code: no comments, no strings. */
    private static function executableReferences(string $src, string $name): int
    {
        $found = 0;
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === $name) {
                $found++;
            }
        }

        return $found;
    }
}
