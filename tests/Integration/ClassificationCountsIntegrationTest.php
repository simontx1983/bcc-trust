<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Admin\Views\DiscoveryScanPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Confirmed vs probable, against a real database.
 *
 * ── WHY REAL MySQL ──────────────────────────────────────────────────────
 * The bug was in SQL: one `COUNT(*) … WHERE classification IN (confirmed,
 * probable)` behind a method named "collection families". A double asked
 * "how many are confirmed?" answers whatever it was told; only the real
 * predicate can show that the split actually splits.
 *
 * ⚠ THE FIXTURE IS THE LIVE ONE. 742 families with 12 confirmed, 1 probable,
 * 195 terminal-negative and 62 temporarily unreachable — Cosmos Hub on
 * 2026-09-07 after run 5, the session whose panel said "13 confirmed".
 */
#[CoversClass(CosmwasmCodeFamilyRepository::class)]
#[Group('integration')]
final class ClassificationCountsIntegrationTest extends TestCase
{
    private const CHAIN = 90807;

    private const OPERATOR = 4247;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . CosmwasmCodeFamilyRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . ChainCheckpointRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
        $wpdb->query('DELETE FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    /**
     * Seed the exact live shape: 742 families, 546 settled.
     *
     * 12 confirmed · 1 probable · 195 not_cw721 · 62 unreachable · the rest
     * unexamined (`classified_at IS NULL`, which is what makes them count as
     * remaining rather than as a verdict).
     */
    private function seedLiveShape(): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= 742; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);

        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 742, true);

        $plan = array_merge(
            array_fill(0, 12, CosmwasmClassifier::CONFIRMED),
            array_fill(0, 1, CosmwasmClassifier::PROBABLE),
            array_fill(0, 195, CosmwasmClassifier::NOT_CW721),
            array_fill(0, 62, CosmwasmClassifier::UNREACHABLE)
        );

        $codeId = 1;
        foreach ($plan as $classification) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId++,
                [
                    'classification'     => $classification,
                    'reason'             => 'sampled:1',
                    'probes_ok'          => 'contract_info',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforclassificationsplit0',
                0
            );
        }
    }

    // ── (1) THE SPLIT ITSELF ────────────────────────────────────────────

    /** ⚠ 12 and 1 — never 13. */
    public function testConfirmedAndProbableAreCountedSeparately(): void
    {
        $this->seedLiveShape();

        self::assertSame(12, CosmwasmCodeFamilyRepository::countConfirmedFamiliesOrThrow(self::CHAIN));
        self::assertSame(1, CosmwasmCodeFamilyRepository::countProbableFamiliesOrThrow(self::CHAIN));
    }

    /** Probable never increments the confirmed count, at any volume. */
    public function testAddingProbableFamiliesNeverMovesTheConfirmedCount(): void
    {
        $this->seedLiveShape();
        $before = CosmwasmCodeFamilyRepository::countConfirmedFamiliesOrThrow(self::CHAIN);

        // Promote ten unexamined families to PROBABLE.
        for ($codeId = 400; $codeId < 410; $codeId++) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                [
                    'classification'     => CosmwasmClassifier::PROBABLE,
                    'reason'             => 'num_tokens_only',
                    'probes_ok'          => 'num_tokens',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforclassificationsplit0',
                0
            );
        }

        self::assertSame($before, CosmwasmCodeFamilyRepository::countConfirmedFamiliesOrThrow(self::CHAIN));
        self::assertSame(11, CosmwasmCodeFamilyRepository::countProbableFamiliesOrThrow(self::CHAIN));
    }

    /**
     * ⚠ THE READ MODEL EXPOSES BOTH, AND THE LIVE NUMBERS RECONCILE.
     *
     * 742 = 546 classified + 196 remaining, and the 546 breaks down into
     * 12 confirmed + 1 probable + 195 negative + 338 settled-inconclusive.
     */
    public function testTheReadModelReportsTheLiveNumbers(): void
    {
        $this->seedLiveShape();

        $p = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertTrue($p['ok']);
        self::assertSame(742, $p['total_families']);
        self::assertSame(12, $p['confirmed_families']);
        self::assertSame(1, $p['probable_families']);
        self::assertSame(195, $p['negative_families']);
        self::assertSame(0, $p['exhausted_families'], 'nothing hit MAX_RETRIES');
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);

        // ⚠ The ambiguous combined key must be gone, not merely unused.
        self::assertArrayNotHasKey('collection_families', $p);
    }

    /** Probable is excluded from remaining — settled for the SCANNER. */
    public function testProbableIsNotCountedAsRemainingScannerWork(): void
    {
        $this->seedLiveShape();
        $before = DiscoveryScanProgress::forChain(self::CHAIN)['remaining_families'];

        CosmwasmCodeFamilyRepository::recordClassification(
            self::CHAIN,
            500,
            [
                'classification'     => CosmwasmClassifier::PROBABLE,
                'reason'             => 'info_only',
                'probes_ok'          => 'contract_info',
                'probes_failed'      => '',
                'last_error'         => '',
                'classifier_version' => CosmwasmClassifier::VERSION,
            ],
            'cosmos1testcontractaddressforclassificationsplit0',
            0
        );

        $after = DiscoveryScanProgress::forChain(self::CHAIN);

        self::assertSame($before - 1, $after['remaining_families'], 'it left the queue');
        self::assertSame(2, $after['probable_families'], '…and became probable, not confirmed');
        self::assertSame(12, $after['confirmed_families']);
    }

    // ── (2) FAIL-CLOSED ─────────────────────────────────────────────────

    /** ⚠ A FAILED READ IS NOT ZERO — for either count. */
    public function testBothCountsFailClosed(): void
    {
        $this->seedLiveShape();
        $wpdb  = $GLOBALS['wpdb'];
        $table = CosmwasmCodeFamilyRepository::table();

        $wpdb->query("ALTER TABLE `{$table}` RENAME TO `{$table}_hidden`");

        try {
            $threw = 0;
            foreach (['countConfirmedFamiliesOrThrow', 'countProbableFamiliesOrThrow'] as $method) {
                try {
                    CosmwasmCodeFamilyRepository::$method(self::CHAIN);
                    self::fail($method . ' returned instead of throwing on a broken read');
                } catch (RepositoryReadFailure) {
                    $threw++;
                }
            }
            self::assertSame(2, $threw, 'both counts must fail closed');

            // …and the read model degrades to UNKNOWN rather than to zero.
            $p = DiscoveryScanProgress::forChain(self::CHAIN);
            self::assertFalse($p['ok']);
            self::assertNull($p['confirmed_families']);
            self::assertNull($p['probable_families']);
            self::assertSame(DiscoveryScanProgress::UNKNOWN, $p['scan_complete']);
        } finally {
            $wpdb->query("ALTER TABLE `{$table}_hidden` RENAME TO `{$table}`");
        }
    }

    // ── (3) WHAT THE OPERATOR READS ─────────────────────────────────────

    /** ⚠ THE LIVE REGRESSION, RENDERED: 12 confirmed, 1 probable — not 13. */
    public function testThePanelReportsTwelveConfirmedAndOneProbable(): void
    {
        $this->seedLiveShape();

        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );
        self::assertIsArray($created);
        $token = DiscoveryRunRepository::claim((int) $created['id']);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            (int) $created['id'],
            $token,
            'session_chunk_ceiling',
            true,
            ['requests_used' => 772, 'families_seen' => 197, 'collections_emitted' => 6]
        ));

        ob_start();
        DiscoveryScanPanel::render(
            (object) ['id' => self::CHAIN, 'slug' => 'cosmos', 'name' => 'Cosmos Hub'],
            true,
            ''
        );
        $html = (string) ob_get_clean();

        self::assertStringContainsString('12 NFT collection families confirmed.', $html);
        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $html);
        self::assertStringContainsString('1 possible collection family needs your review', $html);

        // ⚠ THE FALSE SENTENCE, IN EVERY PHRASING.
        self::assertStringNotContainsString('13 NFT collection families', $html);
        self::assertStringNotContainsString('13 NFT collection families are confirmed so far', $html);
        self::assertStringNotContainsString('13 NFT collection families confirmed', $html);
    }

    /**
     * ⚠ A PROBABLE-ONLY COMPLETE CHAIN MUST NOT CLAIM "NO NFT COLLECTIONS".
     */
    public function testAProbableOnlyCompleteChainNeverClaimsNoCollections(): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= 4; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 4, true);

        // Three settled negatives and ONE probable: scanner work is done.
        foreach ([1 => CosmwasmClassifier::NOT_CW721,
                  2 => CosmwasmClassifier::NOT_CW721,
                  3 => CosmwasmClassifier::NOT_CW721,
                  4 => CosmwasmClassifier::PROBABLE] as $codeId => $classification) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                [
                    'classification'     => $classification,
                    'reason'             => 'sampled:1',
                    'probes_ok'          => 'contract_info',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforclassificationsplit0',
                0
            );
        }

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(0, $p['remaining_families'], 'precondition: scanner work is finished');
        self::assertSame(1, $p['probable_families']);
        self::assertSame(0, $p['confirmed_families']);

        $sentence = DiscoveryScanProgress::summarySentence($p);

        self::assertStringContainsString('Scanning complete.', $sentence);
        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $sentence);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $sentence);
    }

    /** With nothing confirmed AND nothing probable, the final zero returns. */
    public function testAGenuinelyEmptyCompleteChainMaySayTheFinalZero(): void
    {
        $families = [];
        for ($codeId = 1; $codeId <= 3; $codeId++) {
            $families[] = ['code_id' => $codeId, 'checksum' => sprintf('%064x', $codeId)];
        }
        CosmwasmCodeFamilyRepository::recordDiscovered(self::CHAIN, $families);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        ChainCheckpointRepository::recordCwCodeProgress(self::CHAIN, null, 3, true);

        for ($codeId = 1; $codeId <= 3; $codeId++) {
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                [
                    'classification'     => CosmwasmClassifier::NOT_CW721,
                    'reason'             => 'sampled:1',
                    'probes_ok'          => 'contract_info',
                    'probes_failed'      => '',
                    'last_error'         => '',
                    'classifier_version' => CosmwasmClassifier::VERSION,
                ],
                'cosmos1testcontractaddressforclassificationsplit0',
                0
            );
        }

        $p = DiscoveryScanProgress::forChain(self::CHAIN);
        self::assertSame(DiscoveryScanProgress::YES, $p['scan_complete']);
        self::assertSame(0, $p['confirmed_families']);
        self::assertSame(0, $p['probable_families']);

        self::assertStringContainsString(
            'No supported NFT collections were confirmed',
            DiscoveryScanProgress::summarySentence($p)
        );
    }
}
