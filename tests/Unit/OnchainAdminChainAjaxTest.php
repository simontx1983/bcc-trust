<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage for the two per-chain AJAX refresh handlers.
 *
 * These were NOT converted to POST — they were already POST, already
 * nonce-checked, and are not replayable by a browser refresh. Batch 1 gave
 * them the other two treatments: a durable audit row, and sanitisation of the
 * raw `Throwable::getMessage()` they previously painted straight into the
 * page (which can carry SQL fragments and absolute paths).
 *
 * The bounds tests exist because the conversion must not have changed what
 * the handlers ask the fetchers for — `fetch_top_collections(100)` in
 * particular is the only cap on the collection path.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OnchainAdminChainAjaxTest extends TestCase
{
    private const CHAIN_ID = 4;
    private const NONCE = 'bcc_chain_refresh';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/onchain-admin-render-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ValidatorRepository::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Factories\FetcherFactory::reset();
        \BCC\Core\Cron\AsyncDispatcher::reset();
        \BccAjaxRecorder::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'ethereum');

        $_POST = ['chain_id' => self::CHAIN_ID];
        $_GET  = [];
    }

    /**
     * Run an AJAX handler to completion.
     *
     * In production wp_send_json_*() calls wp_die(), which EXITS — the
     * handler's own `catch (\Throwable)` never sees it. In-process the shim
     * can only throw, and that broad catch WOULD swallow it, so we absorb the
     * terminator here and assert against BccAjaxRecorder, which holds the
     * response production would have sent.
     *
     * @param callable-string|array{class-string, string} $handler
     */
    private function invoke(array $handler): void
    {
        try {
            $handler();
        } catch (\BccAjaxResponse) {
            // Terminator reached — this is the normal path.
        }
    }

    // ── 1. Capability rejection ─────────────────────────────────────────────

    public function testValidatorRefreshRejectsMissingCapability(): void
    {
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction = self::NONCE;

        try {
            ChainsPage::ajax_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertFalse($r->success);
            $this->assertSame('Unauthorized.', $r->message());
        }

        $this->assertSame(0, \BCC\Trust\Onchain\Repositories\ValidatorRepository::$upsertCalls);
        $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    public function testCollectionRefreshRejectsMissingCapability(): void
    {
        \BccAdminTestState::$can = false;
        \BccAdminTestState::$validNonceAction = self::NONCE;

        try {
            ChainsPage::ajax_collection_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertFalse($r->success);
        }

        $this->assertSame(0, \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertCalls);
    }

    // ── 2. Nonce rejection ──────────────────────────────────────────────────

    public function testValidatorRefreshRejectsInvalidNonce(): void
    {
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            ChainsPage::ajax_refresh();
        } finally {
            $this->assertSame(0, \BCC\Trust\Onchain\Repositories\ValidatorRepository::$upsertCalls);
            $this->assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
        }
    }

    public function testCollectionRefreshRejectsInvalidNonce(): void
    {
        \BccAdminTestState::$validNonceAction = null;

        $this->expectException(\BccAdminDie::class);
        try {
            ChainsPage::ajax_collection_refresh();
        } finally {
            $this->assertSame(0, \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertCalls);
        }
    }

    // ── 3. Invalid chain rejection ──────────────────────────────────────────

    public function testValidatorRefreshRejectsUnknownChain(): void
    {
        $_POST['chain_id'] = 999;
        \BccAdminTestState::$validNonceAction = self::NONCE;

        try {
            ChainsPage::ajax_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertFalse($r->success);
            $this->assertSame('Chain not found.', $r->message());
        }

        $this->assertSame(0, \BCC\Trust\Onchain\Repositories\ValidatorRepository::$upsertCalls);
    }

    public function testCollectionRefreshRejectsUnknownChain(): void
    {
        $_POST['chain_id'] = 999;
        \BccAdminTestState::$validNonceAction = self::NONCE;

        try {
            ChainsPage::ajax_collection_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertSame('Chain not found.', $r->message());
        }

        $this->assertSame(0, \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertCalls);
    }

    // ── 4 + 5. Successful dispatch exactly once, with audit ─────────────────

    public function testValidatorRefreshDispatchesOnceAndAudits(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;

        $this->invoke([ChainsPage::class, 'ajax_refresh']);

        $r = \BccAjaxRecorder::first();
        $this->assertTrue($r->success);
        $this->assertStringContainsString('3 indexed', $r->message());

        $this->assertSame(1, \BCC\Trust\Onchain\Repositories\ValidatorRepository::$upsertCalls);

        // Assert the FIRST row: in production wp_send_json_success() exits,
        // so the success row is the last thing written. In-process the shim's
        // throw is caught by the handler's own catch(\Throwable), appending a
        // harness-only failure row after it.
        $rows = \BCC\Trust\Core\Security\AuditLogger::$rows;
        $this->assertSame('admin_chain_validators_refreshed', \BCC\Trust\Core\Security\AuditLogger::actions()[0]);
        $this->assertSame('chain', $rows[0]['targetType']);
        $this->assertSame(self::CHAIN_ID, $rows[0]['targetId']);
        $this->assertSame(3, $rows[0]['meta']['total']);
    }

    public function testCollectionRefreshDispatchesOnceAndAudits(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;

        $this->invoke([ChainsPage::class, 'ajax_collection_refresh']);

        $r = \BccAjaxRecorder::first();
        $this->assertTrue($r->success);
        $this->assertStringContainsString('7 collections indexed', $r->message());

        $this->assertSame(1, \BCC\Trust\Onchain\Repositories\CollectionRepository::$upsertCalls);
        // First row only — see the note in the validator test above.
        $this->assertSame('admin_chain_collections_refreshed', \BCC\Trust\Core\Security\AuditLogger::actions()[0]);
    }

    // ── 6 + 7. Failure audit; no provider/exception detail in the response ──

    public function testValidatorRefreshFailureAuditsAndHidesProviderDetail(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;
        $fetcher = new \BccFakeFetcher();
        $fetcher->throws = new \RuntimeException(
            'Alchemy 401 for https://eth-mainnet.g.alchemy.com/v2/SECRETKEY at /var/www/html/x.php:88'
        );
        \BCC\Trust\Onchain\Factories\FetcherFactory::$fetcher = $fetcher;

        try {
            ChainsPage::ajax_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertFalse($r->success);
            $msg = $r->message();

            $this->assertMatchesRegularExpression('/bcc-[0-9a-f]{8}/', $msg);
            $this->assertStringNotContainsString('SECRETKEY', $msg);
            $this->assertStringNotContainsString('alchemy.com', $msg);
            $this->assertStringNotContainsString('/var/www', $msg);
            $this->assertStringNotContainsString('Alchemy 401', $msg);
        }

        $this->assertSame(
            ['admin_chain_validators_refresh_failed'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );

        // Full provider detail is retained internally for the engineer.
        $errors = \BCC\Core\Log\Logger::ofLevel('error');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('SECRETKEY', $errors[0]['context']['message']);
    }

    public function testCollectionRefreshFailureAuditsAndHidesProviderDetail(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;
        $fetcher = new \BccFakeFetcher();
        $fetcher->throws = new \RuntimeException('Duplicate entry in wp_bcc_onchain_collections');
        \BCC\Trust\Onchain\Factories\FetcherFactory::$fetcher = $fetcher;

        try {
            ChainsPage::ajax_collection_refresh();
            $this->fail('Expected an error response.');
        } catch (\BccAjaxResponse $r) {
            $this->assertStringNotContainsString('wp_bcc_onchain_collections', $r->message());
            $this->assertMatchesRegularExpression('/bcc-[0-9a-f]{8}/', $r->message());
        }

        $this->assertSame(
            ['admin_chain_collections_refresh_failed'],
            \BCC\Trust\Core\Security\AuditLogger::actions()
        );
    }

    // ── 8. Bounds unchanged ─────────────────────────────────────────────────

    public function testCollectionRefreshStillRequestsExactlyOneHundred(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;

        $this->invoke([ChainsPage::class, 'ajax_collection_refresh']);

        // The only cap on this path. Batch 1 must not have widened it.
        $this->assertSame(100, \BccFakeFetcher::$lastCollectionsLimit);
    }

    public function testValidatorRefreshStillDefersEnrichmentToCronRatherThanInlining(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;

        $this->invoke([ChainsPage::class, 'ajax_refresh']);
        $this->assertStringContainsString('Enrichment scheduled.', \BccAjaxRecorder::first()->message());

        // Enrichment stays a scheduled single event — inlining 500 sequential
        // API calls in an AJAX request is the timeout the original code
        // deliberately avoided.
        $scheduled = \BCC\Core\Cron\AsyncDispatcher::$scheduled;
        $this->assertCount(1, $scheduled);
        $this->assertSame('bcc_refresh_validators', $scheduled[0]['hook']);
    }

    public function testValidatorRefreshDoesNotDoubleScheduleEnrichment(): void
    {
        \BccAdminTestState::$validNonceAction = self::NONCE;
        $GLOBALS['bcc_test_next_scheduled'] = time() + 60;

        $this->invoke([ChainsPage::class, 'ajax_refresh']);
        $this->assertStringContainsString('Enrichment already scheduled.', \BccAjaxRecorder::first()->message());

        $this->assertSame([], \BCC\Core\Cron\AsyncDispatcher::$scheduled);
    }

    public function testTransportFailureStillDistinguishedFromEmptyResult(): void
    {
        // Pre-existing diagnostic behaviour that must survive: an upstream
        // transport error and a genuinely empty result are different messages.
        \BccAdminTestState::$validNonceAction = self::NONCE;
        $fetcher = new \BccFakeFetcher();
        $fetcher->validators = [];
        $fetcher->fetchError = 'connection refused';
        \BCC\Trust\Onchain\Factories\FetcherFactory::$fetcher = $fetcher;

        $this->invoke([ChainsPage::class, 'ajax_refresh']);

        $r = \BccAjaxRecorder::first();
        $this->assertFalse($r->success);
        $this->assertStringContainsString('connection refused', $r->message());
    }
}
