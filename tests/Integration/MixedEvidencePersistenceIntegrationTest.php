<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mixed evidence and enumeration telemetry, against a real database.
 *
 * ── WHY REAL MySQL ──────────────────────────────────────────────────────
 * Two of the claims in PR 7.6 are claims about STORED BYTES, and a double
 * cannot make them:
 *
 *   1. the corrected reason survives the round trip and lands in
 *      `classification_reason`, a VARCHAR(64) — a longer token would be
 *      silently truncated by MySQL and a doubled repository would never
 *      show it;
 *   2. `cw_last_error` holds a bounded token and never a provider sentence.
 *
 * ⚠ THE FIXTURE IS THE LIVE ONE. The contract shapes below are the ones
 * measured on Cosmos Hub on 2026-09-08: code family 550 produced BOTH a
 * three-refusal `not_cw721` contract AND, one second later, a contract whose
 * third probe was unreadable and which was therefore blamed on the node.
 */
#[CoversClass(CosmwasmClassifier::class)]
#[Group('integration')]
final class MixedEvidencePersistenceIntegrationTest extends TestCase
{
    private const CHAIN = 90811;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . CosmwasmContractRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /** @return array{probe: string, ok: bool, kind: string, excerpt: string} */
    private function probe(string $name, string $kind, string $excerpt = ''): array
    {
        return ['probe' => $name, 'ok' => false, 'kind' => $kind, 'excerpt' => $excerpt];
    }

    private function seedFamily(int $codeId): void
    {
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, [
            ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)],
        ]);
    }

    /** The verbatim wire message from the code-550 contract, as stored. */
    private const LIVE_REJECTION =
        'Error parsing into type open_edition_minter_merkle_wl::msg::QueryMsg: unknown variant '
        . '`num_tokens`, expected one of `config`, `start_time`, `end_time`, `mint_price`: '
        . 'query wasm contract failed';

    // ── the verdict, persisted ──────────────────────────────────────────

    /**
     * The corrected reason survives a real VARCHAR(64) round trip.
     *
     * ⚠ `mixed_evidence_probe_ambiguous` is 30 characters. The column is 64.
     * If a future rename overflows it, MySQL truncates and the panel starts
     * rendering a mangled token — so the assertion is on the value READ BACK,
     * never on the value passed in.
     */
    public function testTheMixedEvidenceReasonRoundTripsIntact(): void
    {
        $verdict = CosmwasmClassifier::classify([
            $this->probe('num_tokens', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, self::LIVE_REJECTION),
            $this->probe('contract_info', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', CosmwasmClassifier::KIND_HTTP_4XX),
        ]);

        self::assertSame(CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS, $verdict['reason']);

        $this->seedFamily(550);
        CosmwasmCodeFamilyRepository::recordClassification(self::CHAIN, 550, $verdict, null, 0);

        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN, 550);
        self::assertNotNull($row);
        self::assertSame(CosmwasmClassifier::UNREACHABLE, (string) $row->classification);
        self::assertSame(
            CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS,
            (string) $row->classification_reason,
            'the reason must survive the column width exactly'
        );
        self::assertLessThanOrEqual(64, strlen(CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS));
    }

    /** Two contracts of the SAME family, differing only in one probe. */
    public function testTheLiveCodeFamily550ShapesAreDistinguished(): void
    {
        $allRefused = CosmwasmClassifier::classify([
            $this->probe('num_tokens', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, self::LIVE_REJECTION),
            $this->probe('contract_info', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
        ]);
        $oneUnreadable = CosmwasmClassifier::classify([
            $this->probe('num_tokens', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, self::LIVE_REJECTION),
            $this->probe('contract_info', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', CosmwasmClassifier::KIND_HTTP_4XX),
        ]);

        // Before PR 7.6 the second one read `node_unreachable`.
        self::assertSame(CosmwasmClassifier::NOT_CW721, $allRefused['classification']);
        self::assertSame(CosmwasmClassifier::UNREACHABLE, $oneUnreadable['classification']);
        self::assertNotSame(
            CosmwasmClassifier::REASON_NODE_UNREACHABLE,
            $oneUnreadable['reason'],
            'the node answered both contracts; only one reply was unreadable'
        );

        $this->seedFamily(550);
        // ⚠ Addresses are lowercased on write by the repository, so the
        // fixture uses lowercase throughout — otherwise the read-back would
        // miss and the assertions would be comparing against nothing.
        CosmwasmContractRepository::recordDiscovered(self::CHAIN, 550, [
            ['contract_address' => 'cosmos1aaarefusedall', 'denied' => false],
            ['contract_address' => 'cosmos1bbbambiguous', 'denied' => false],
        ]);
        CosmwasmContractRepository::recordClassification(self::CHAIN, 'cosmos1aaarefusedall', $allRefused, 0);
        CosmwasmContractRepository::recordClassification(self::CHAIN, 'cosmos1bbbambiguous', $oneUnreadable, 0);

        $wpdb = $GLOBALS['wpdb'];
        $rows = $wpdb->get_results(
            'SELECT contract_address, classification, classification_reason FROM `'
            . CosmwasmContractRepository::table() . '` WHERE chain_id = ' . self::CHAIN
            . ' ORDER BY contract_address'
        );

        self::assertCount(2, $rows);
        $byAddress = [];
        foreach ($rows as $r) {
            $byAddress[(string) $r->contract_address] = $r;
        }

        self::assertArrayHasKey('cosmos1aaarefusedall', $byAddress);
        self::assertArrayHasKey('cosmos1bbbambiguous', $byAddress);

        self::assertSame(
            CosmwasmClassifier::NOT_CW721,
            (string) $byAddress['cosmos1aaarefusedall']->classification,
            'three readable refusals settle as a terminal negative'
        );
        self::assertSame(
            CosmwasmClassifier::UNREACHABLE,
            (string) $byAddress['cosmos1bbbambiguous']->classification
        );
        self::assertSame(
            CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS,
            (string) $byAddress['cosmos1bbbambiguous']->classification_reason,
            'the same family, one unreadable reply — and the node is not blamed'
        );
    }

    /** A genuine node fault still persists as a node fault. */
    public function testAGenuineNodeFaultStillPersistsAsNodeUnreachable(): void
    {
        $verdict = CosmwasmClassifier::classify([
            $this->probe('num_tokens', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', CosmwasmClassifier::KIND_NODE_ERROR, 'rpc error: code = Unavailable'),
        ]);

        $this->seedFamily(551);
        CosmwasmCodeFamilyRepository::recordClassification(self::CHAIN, 551, $verdict, null, 0);

        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN, 551);
        self::assertNotNull($row);
        self::assertSame(CosmwasmClassifier::REASON_NODE_UNREACHABLE, (string) $row->classification_reason);
    }

    /** Mixed evidence stays requeueable in the real pending predicate. */
    public function testMixedEvidenceStaysInTheQueue(): void
    {
        $verdict = CosmwasmClassifier::classify([
            $this->probe('num_tokens', CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', CosmwasmClassifier::KIND_MALFORMED),
        ]);

        $this->seedFamily(552);
        CosmwasmCodeFamilyRepository::recordClassification(self::CHAIN, 552, $verdict, null, 0);

        $row = CosmwasmCodeFamilyRepository::find(self::CHAIN, 552);
        self::assertNotNull($row);
        self::assertContains(
            (string) $row->classification,
            CosmwasmClassifier::requeueableClassifications(),
            'ambiguous evidence must remain retryable work, not a terminal verdict'
        );
    }

    // ── enumeration telemetry, persisted ────────────────────────────────

    /** A bounded code lands in cw_last_error and reads back byte-identical. */
    public function testEnumerationFailureCodePersists(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);

        self::assertTrue(
            ChainCheckpointRepository::recordCwEnumerationFailure(self::CHAIN, CosmwasmEnumerationFailure::HTTP_5XX)
        );

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertSame(CosmwasmEnumerationFailure::HTTP_5XX, (string) $row->cw_last_error);
    }

    /**
     * ⚠ THE COLUMN REFUSES PROSE, AT THE DATABASE BOUNDARY.
     *
     * Every hostile string a provider could put in a response body is
     * rejected before the write, so the stored value can only ever be a
     * token. This is the assertion that keeps a rendered admin column safe.
     */
    public function testProviderProseCanNeverReachTheColumn(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwEnumerationFailure(self::CHAIN, CosmwasmEnumerationFailure::TRANSPORT);

        foreach ([
            self::LIVE_REJECTION,
            '<script>alert(1)</script>',
            "'; DROP TABLE wp_bcc_chains; --",
            'https://user:secret@rest.example.com/path',
            'Authorization: Bearer sk_live_abc123',
            'rpc error: code = Unavailable desc = connection refused',
            str_repeat('X', 400),
            '',
        ] as $hostile) {
            self::assertFalse(
                ChainCheckpointRepository::recordCwEnumerationFailure(self::CHAIN, $hostile),
                'a non-token must be refused'
            );
        }

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertSame(
            CosmwasmEnumerationFailure::TRANSPORT,
            (string) $row->cw_last_error,
            'the earlier bounded token must be untouched by every rejected write'
        );
    }

    /** Every vocabulary token fits and round-trips. */
    public function testEveryBoundedCodeRoundTrips(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);

        foreach (CosmwasmEnumerationFailure::codes() as $code) {
            self::assertTrue(ChainCheckpointRepository::recordCwEnumerationFailure(self::CHAIN, $code));
            $row = ChainCheckpointRepository::get(self::CHAIN);
            self::assertNotNull($row);
            self::assertSame($code, (string) $row->cw_last_error);
        }
    }

    /** A successful enumeration clears the stale failure. */
    public function testASuccessfulEnumerationClearsTheFailure(): void
    {
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwEnumerationFailure(self::CHAIN, CosmwasmEnumerationFailure::RATE_LIMITED);

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertSame(CosmwasmEnumerationFailure::RATE_LIMITED, (string) $row->cw_last_error);

        ChainCheckpointRepository::advanceCwCodeWatermark(self::CHAIN, 900);

        $row = ChainCheckpointRepository::get(self::CHAIN);
        self::assertNotNull($row);
        self::assertNull(
            $row->cw_last_error,
            'a confirmed successful enumeration must supersede the stale failure'
        );
    }

    /** A zero/negative chain id is refused outright. */
    public function testAnInvalidChainIdIsRefused(): void
    {
        self::assertFalse(
            ChainCheckpointRepository::recordCwEnumerationFailure(0, CosmwasmEnumerationFailure::TRANSPORT)
        );
        self::assertFalse(
            ChainCheckpointRepository::recordCwEnumerationFailure(-5, CosmwasmEnumerationFailure::TRANSPORT)
        );
    }
}
