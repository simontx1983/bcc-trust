<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\REST\HeliusWebhookEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Helius unmark-on-failed-ingest.
 *
 * The webhook marks each signature seen (committed) BEFORE the single
 * batch ingest. If ingest throws, the marked signatures must be unmarked
 * so Helius's redelivery can re-process — otherwise the batch is lost
 * permanently (Solana has no polling walker; a resend is refused as a
 * replay). Endpoint stays always-200 throughout.
 *
 * ## Isolation
 * Subprocess-only; setUp() pulls in tests/Stubs/helius-webhook-stubs.php
 * (fakes the repository, indexer, fetcher, chain, delivery log, IP
 * resolver, logger, metrics, and the minimal WP surface at their FQNs)
 * and defines the auth secret so hash_equals passes.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HeliusIngestUnmarkTest extends TestCase
{
    private const SECRET = 'test-helius-secret';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/helius-webhook-stubs.php';
        if (!defined('BCC_HELIUS_WEBHOOK_SECRET')) {
            define('BCC_HELIUS_WEBHOOK_SECRET', self::SECRET);
        }
        $GLOBALS['__bcc_helius_fixture'] = [
            'ingest_throws' => false,
            'delete_result' => 0,
            'seen'          => [],
            'marked'        => [],
            'deleted_args'  => [],
            'ingest_calls'  => 0,
            'metrics'       => [],
            'fail_on'       => [],
        ];
    }

    /** @param list<string> $signatures */
    private static function request(array $signatures): \WP_REST_Request
    {
        $txs = array_map(static fn(string $s): array => ['signature' => $s], $signatures);
        return new \WP_REST_Request(self::SECRET, $txs);
    }

    public function testIngestFailureUnmarksExactlyThisDeliverysSignatures(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['ingest_throws'] = true;
        $f['delete_result'] = 2; // both unmarked successfully
        $f['seen']['old-replayed'] = true; // belongs to a prior delivery

        $resp = HeliusWebhookEndpoint::handle(self::request(['sig-a', 'old-replayed', 'sig-b']));

        self::assertSame(200, $resp->get_status());
        // Only the two newly-marked signatures are unmarked — the replay is excluded.
        self::assertSame([['sig-a', 'sig-b']], $f['deleted_args']);
        self::assertContains(['helius_dedup', 'ingest_failed_unmarked'], $f['metrics']);
        self::assertNotContains(['helius_dedup', 'unmark_failed'], $f['metrics']);
    }

    public function testIncompleteUnmarkRecordsUnmarkFailed(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['ingest_throws'] = true;
        $f['delete_result'] = null; // DB error / short delete

        $resp = HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b']));

        self::assertSame(200, $resp->get_status());
        self::assertSame([['sig-a', 'sig-b']], $f['deleted_args']);
        self::assertContains(['helius_dedup', 'unmark_failed'], $f['metrics']);
        self::assertNotContains(['helius_dedup', 'ingest_failed_unmarked'], $f['metrics']);
    }

    public function testSuccessfulIngestNeverUnmarks(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['ingest_throws'] = false;

        $resp = HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b']));

        self::assertSame(200, $resp->get_status());
        self::assertSame(1, $f['ingest_calls']);
        self::assertSame([], $f['deleted_args']);
        self::assertSame([], $f['metrics']);
    }

    /**
     * F-67: a dedupe write that fails part-way through a delivery is NOT a
     * replay. The delivery is not ingested at all, and the signatures it had
     * already marked are reopened, so a redelivery is processed instead of
     * refused. Before, the failed signature was skipped as "already seen"
     * and the rest of the batch was ingested without it: that transaction's
     * events were lost for good.
     */
    public function testADedupeWriteFailureIngestsNothingAndReopensThisDelivery(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['fail_on']['sig-b'] = true;
        $f['delete_result']    = 1;

        $resp = HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b', 'sig-c']));

        self::assertSame(200, $resp->get_status(), 'the always-200 posture is unchanged');
        self::assertSame(0, $f['ingest_calls'], 'nothing is ingested from a delivery whose dedupe failed');
        self::assertSame([['sig-a']], $f['deleted_args'], 'only what this delivery marked is reopened');
        self::assertNotContains('sig-c', $f['marked'], 'the batch stops at the failure');
        self::assertContains(['helius_dedup', 'ingest_failed_unmarked'], $f['metrics']);
        self::assertNotContains(['helius_dedup', 'replay_skipped'], $f['metrics'], 'a failure is never counted as a replay');
    }

    public function testADedupeFailureOnTheFirstSignatureMarksAndIngestsNothing(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['fail_on']['sig-a'] = true;

        HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b']));

        self::assertSame(0, $f['ingest_calls']);
        self::assertSame([], $f['marked']);
        self::assertSame([], $f['deleted_args'], 'nothing to reopen');
        self::assertContains(['helius_dedup', 'ingest_failed_unmarked'], $f['metrics']);
    }

    public function testADedupeFailureWhoseReopenFailsIsReportedAsUnmarkFailed(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['fail_on']['sig-b'] = true;
        $f['delete_result']    = null;

        HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b']));

        self::assertSame(0, $f['ingest_calls']);
        self::assertContains(['helius_dedup', 'unmark_failed'], $f['metrics']);
    }

    public function testAllReplayDeliveryNeverIngestsOrUnmarks(): void
    {
        $f = &$GLOBALS['__bcc_helius_fixture'];
        $f['ingest_throws'] = true; // would throw IF ingest ran
        $f['seen']['sig-a'] = true;
        $f['seen']['sig-b'] = true;

        $resp = HeliusWebhookEndpoint::handle(self::request(['sig-a', 'sig-b']));

        self::assertSame(200, $resp->get_status());
        // No new events → ingest never runs → no unmark. (The replay path
        // still records its own replay_skipped metric — that's expected.)
        self::assertSame(0, $f['ingest_calls']);
        self::assertSame([], $f['deleted_args']);
        self::assertNotContains(['helius_dedup', 'ingest_failed_unmarked'], $f['metrics']);
        self::assertNotContains(['helius_dedup', 'unmark_failed'], $f['metrics']);
    }
}
