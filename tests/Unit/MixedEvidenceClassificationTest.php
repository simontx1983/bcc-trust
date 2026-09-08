<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmEvidenceNarrator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A contract that answered is not an unreachable node.
 *
 * ── THE MEASUREMENT THESE TESTS ENCODE ──────────────────────────────────
 * PR 7.6 Phase 1, 2026-09-08. Cosmos Hub carried 82 contracts across 65
 * code families recorded as `temporarily_unreachable / node_unreachable`.
 * Every one of them had the same shape — two decisive refusals from a
 * working contract plus one probe that could not be read:
 *
 *   num_tokens:query_unsupported,
 *   contract_info:query_unsupported,
 *   get_collection_info_and_extension:http_4xx
 *
 * Two of them shared code id 550 and were queried one second apart. One got
 * three readable refusals and settled, correctly, as `not_cw721`. The other
 * got one unreadable reply and was blamed on the node. Re-querying it
 * directly returned a clean `unknown variant` rejection — on the configured
 * endpoint AND on an independent one — and its own stored `last_error` was
 * that rejection. It is an ordinary `open_edition_minter_merkle_wl` minter.
 *
 * In 473 rows carrying probe telemetry there was not ONE `node_error` and
 * not ONE `transport` kind. The node never failed. The reason token said it
 * had, 82 times.
 *
 * ── WHAT IS AND IS NOT BEING CHANGED ────────────────────────────────────
 * The VERDICT is unchanged: still `temporarily_unreachable`, still
 * retryable, still not terminal. Only the ATTRIBUTION changes. These tests
 * assert both halves — that the reason stops naming the node, and that the
 * verdict does NOT drift toward `not_cw721`, which would manufacture a
 * terminal negative out of a reply nobody could read.
 */
#[CoversClass(CosmwasmClassifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MixedEvidenceClassificationTest extends TestCase
{
    /**
     * Build a probe outcome in the classifier's own shape.
     *
     * @return array{probe: string, ok: bool, kind: string, excerpt: string}
     */
    private function probe(string $name, bool $ok, string $kind, string $excerpt = ''): array
    {
        return ['probe' => $name, 'ok' => $ok, 'kind' => $kind, 'excerpt' => $excerpt];
    }

    /** @param list<array{probe: string, ok: bool, kind: string, excerpt: string}> $outcomes */
    private function classify(array $outcomes): array
    {
        return CosmwasmClassifier::classify($outcomes);
    }

    // ── the corrected case ──────────────────────────────────────────────

    /** The exact live shape: two decisive refusals + one unreadable 4xx. */
    public function testDecisiveRefusalsPlusAmbiguousFourXxDoesNotBlameTheNode(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_HTTP_4XX),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame(
            CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS,
            $verdict['reason'],
            'a contract that refused two queries is not an unreachable node'
        );
        self::assertStringNotContainsStringIgnoringCase('node', $verdict['reason']);
    }

    /** Same shape, but the unreadable probe is a malformed body. */
    public function testDecisiveRefusalsPlusMalformedDoesNotBlameTheNode(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_MALFORMED),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame(CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS, $verdict['reason']);
    }

    /**
     * ⚠ THE VERDICT MUST NOT DRIFT TO A NEGATIVE.
     *
     * The tempting "simplification" is to say the two refusals are enough
     * and call it `not_cw721`. They are not: the unread probe is exactly the
     * one that could have said yes. This is the assertion that stops a
     * future refactor from turning an unreadable reply into a terminal
     * negative — which would permanently hide a real collection.
     */
    public function testAmbiguousEvidenceIsNeverPromotedToNegative(): void
    {
        foreach ([CosmwasmClassifier::KIND_HTTP_4XX, CosmwasmClassifier::KIND_MALFORMED] as $ambiguous) {
            $verdict = $this->classify([
                $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
                $this->probe('contract_info', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
                $this->probe('get_collection_info_and_extension', false, $ambiguous),
            ]);

            self::assertNotSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
            self::assertNotSame(CosmwasmClassifier::CONFIRMED, $verdict['classification']);
            self::assertNotSame(CosmwasmClassifier::PROBABLE, $verdict['classification']);
        }
    }

    /** Mixed evidence stays in the queue. */
    public function testMixedEvidenceRemainsRetryable(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_HTTP_4XX),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
        ]);

        self::assertTrue(CosmwasmClassifier::isRetryable($verdict['classification'], 0));
        self::assertFalse(CosmwasmClassifier::isTerminal($verdict['classification']));
        self::assertContains($verdict['classification'], CosmwasmClassifier::requeueableClassifications());
    }

    // ── the preserved cases ─────────────────────────────────────────────

    /** All three decisively refused → terminal negative. Unchanged. */
    public function testEveryProbeUnsupportedIsStillNotCw721(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
        ]);

        self::assertSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
        self::assertSame(CosmwasmClassifier::REASON_NO_CW721_QUERIES, $verdict['reason']);
    }

    /**
     * A genuine node error still blames the node — in ANY position.
     *
     * @param int $position which probe carries the node fault
     */
    #[DataProvider('nodeFaultPositions')]
    public function testGenuineNodeErrorStillBlamesTheNode(int $position, string $kind): void
    {
        $outcomes = [
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
        ];
        $outcomes[$position]['kind'] = $kind;

        $verdict = $this->classify($outcomes);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame(
            CosmwasmClassifier::REASON_NODE_UNREACHABLE,
            $verdict['reason'],
            'a real node/transport fault must still be attributed to the node'
        );
    }

    /** @return list<array{int, string}> */
    public static function nodeFaultPositions(): array
    {
        $out = [];
        foreach ([0, 1, 2] as $i) {
            $out[] = [$i, CosmwasmClassifier::KIND_NODE_ERROR];
            $out[] = [$i, CosmwasmClassifier::KIND_TRANSPORT];
        }

        return $out;
    }

    /**
     * ⚠ A NODE FAULT OUTRANKS AMBIGUITY. If both are present the node is
     * named, because a real provider failure is the more actionable fact
     * and under-reporting it would weaken the breaker's justification.
     */
    public function testANodeFaultBesideAmbiguityStillBlamesTheNode(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_QUERY_UNSUPPORTED),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_HTTP_4XX),
            $this->probe('get_collection_info_and_extension', false, CosmwasmClassifier::KIND_TRANSPORT),
        ]);

        self::assertSame(CosmwasmClassifier::REASON_NODE_UNREACHABLE, $verdict['reason']);
    }

    /**
     * Ambiguity with NO decisive refusal keeps today's reason.
     *
     * The mixed-evidence reason claims decisive refusals exist. Where none
     * do, using it would describe evidence that was never gathered.
     */
    public function testAmbiguityAloneDoesNotClaimDecisiveRefusals(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', false, CosmwasmClassifier::KIND_HTTP_4XX),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_MALFORMED),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertNotSame(CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS, $verdict['reason']);
    }

    // ── partial-answer variants ─────────────────────────────────────────

    /** A partial success plus ambiguity does not blame the node either. */
    public function testPartialAnswerPlusAmbiguityDoesNotBlameTheNode(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', true, CosmwasmClassifier::KIND_NONE),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_HTTP_4XX),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame(CosmwasmClassifier::REASON_PARTIAL_EVIDENCE_AMBIGUOUS, $verdict['reason']);
    }

    /** A partial success plus a real node fault still does. */
    public function testPartialAnswerPlusNodeFaultStillBlamesTheNode(): void
    {
        $verdict = $this->classify([
            $this->probe('num_tokens', true, CosmwasmClassifier::KIND_NONE),
            $this->probe('contract_info', false, CosmwasmClassifier::KIND_NODE_ERROR),
        ]);

        self::assertSame(CosmwasmClassifier::REASON_PARTIAL_NODE_UNREACHABLE, $verdict['reason']);
    }

    // ── the predicate itself ────────────────────────────────────────────

    /** Only node_error and transport accuse the provider. */
    public function testOnlyNodeAndTransportKindsAreNodeCaused(): void
    {
        self::assertTrue(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_NODE_ERROR));
        self::assertTrue(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_TRANSPORT));

        self::assertFalse(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_HTTP_4XX));
        self::assertFalse(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_MALFORMED));
        self::assertFalse(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_QUERY_UNSUPPORTED));
        self::assertFalse(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_NOT_FOUND));
        self::assertFalse(CosmwasmClassifier::isNodeCausedFault(CosmwasmClassifier::KIND_NONE));
    }

    /** Ambiguous kinds stay transient — the work must still be retried. */
    public function testAmbiguousKindsAreStillTransientFaults(): void
    {
        self::assertTrue(CosmwasmClassifier::isTransientFault(CosmwasmClassifier::KIND_HTTP_4XX));
        self::assertTrue(CosmwasmClassifier::isTransientFault(CosmwasmClassifier::KIND_MALFORMED));
    }

    // ── operator prose ──────────────────────────────────────────────────

    /**
     * ⚠ THE NEW SENTENCES MUST NOT MENTION THE NODE. That is the entire
     * point of the reason split: an operator reading it must not be sent to
     * investigate a provider that answered every time.
     */
    public function testAmbiguousReasonsNeverMentionTheNodeOrTheChain(): void
    {
        foreach ([
            CosmwasmClassifier::REASON_MIXED_EVIDENCE_AMBIGUOUS,
            CosmwasmClassifier::REASON_PARTIAL_EVIDENCE_AMBIGUOUS,
        ] as $reason) {
            $sentence = CosmwasmEvidenceNarrator::reasonSentence(
                CosmwasmClassifier::UNREACHABLE,
                $reason
            );

            self::assertNotSame('', trim($sentence), 'the reason must have real prose, not a fallback');
            foreach (['node', 'chain node', 'unreachable', 'offline', 'down'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $sentence,
                    "ambiguous evidence must not be described with '{$forbidden}'"
                );
            }
            self::assertStringContainsStringIgnoringCase('tried again', $sentence);
        }
    }

    /** The genuine node reason still says so, so the split is observable. */
    public function testNodeReasonStillNamesTheNode(): void
    {
        $sentence = CosmwasmEvidenceNarrator::reasonSentence(
            CosmwasmClassifier::UNREACHABLE,
            CosmwasmClassifier::REASON_NODE_UNREACHABLE
        );

        self::assertStringContainsStringIgnoringCase('node', $sentence);
    }
}
