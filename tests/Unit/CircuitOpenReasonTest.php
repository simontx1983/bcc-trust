<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\CosmwasmPassStopReason;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * "The provider is resting" must not read as "your chain is broken".
 *
 * ── THE OPERATOR PROBLEM ────────────────────────────────────────────────
 * Runs 5 and 6 both ended as `chain_refused_to_prepare` — the same token a
 * paused chain, an unsupported chain and a missing driver produce. The
 * operator had pressed Continue on a correctly configured chain and was told
 * it "refused to prepare". The available conclusions were all wrong, and the
 * cheapest one — press Continue again — spends a chunk against a pause that
 * has to elapse on its own.
 */
#[CoversClass(CosmwasmPassStopReason::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CircuitOpenReasonTest extends TestCase
{
    private function budget(): CosmwasmTickBudget
    {
        return new CosmwasmTickBudget(25);
    }

    // ── the mapping ─────────────────────────────────────────────────────

    /** A circuit-open pass gets its own token. */
    public function testCircuitOpenMapsToItsOwnStopReason(): void
    {
        self::assertSame(
            CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN, $this->budget())
        );
    }

    /** ⚠ It must NOT collapse back into the generic refusal. */
    public function testCircuitOpenIsNotTheGenericRefusal(): void
    {
        $reason = CosmwasmPassStopReason::forOutcome(
            CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN,
            $this->budget()
        );

        self::assertNotSame(CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE, $reason);
        self::assertNotSame(
            CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN,
            CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE,
            'the two tokens must be distinct values, not aliases'
        );
    }

    /** The other outcomes are untouched. */
    public function testTheOtherOutcomesAreUnchanged(): void
    {
        $b = $this->budget();

        self::assertSame(
            CosmwasmPassStopReason::LOCK_CONTENDED,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_LOCKED, $b)
        );
        self::assertSame(
            CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_SKIPPED, $b)
        );
        self::assertSame(
            CosmwasmPassStopReason::EXECUTION_FAILED,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_FAILED, $b)
        );
    }

    /** A circuit-open session is a PARTIAL session, never a completed one. */
    public function testCircuitOpenIsPartial(): void
    {
        self::assertTrue(
            CosmwasmPassStopReason::isPartial(CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN),
            'a session cut short by a provider pause must never read as finished'
        );
        self::assertFalse(CosmwasmPassStopReason::isPartial(CosmwasmPassStopReason::PASS_COMPLETED));
    }

    /** The worker outcome constants stay distinct. */
    public function testPassOutcomeConstantsAreDistinct(): void
    {
        $all = [
            CosmwasmDiscoveryWorker::PASS_RAN,
            CosmwasmDiscoveryWorker::PASS_LOCKED,
            CosmwasmDiscoveryWorker::PASS_SKIPPED,
            CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN,
            CosmwasmDiscoveryWorker::PASS_FAILED,
        ];

        self::assertCount(count(array_unique($all)), $all);
    }

    /** The run-level error code exists and is distinct from chain_not_ready. */
    public function testTheRunErrorCodeIsDistinct(): void
    {
        self::assertSame('provider_circuit_open', DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::CHAIN_NOT_READY, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::CHAIN_UNSUPPORTED, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::DISCOVERY_DISABLED, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
    }

    // ── operator wording ────────────────────────────────────────────────

    /**
     * The rendered sentence must carry the three facts an operator needs and
     * none of the internals they cannot act on.
     */
    public function testTheOperatorSentenceIsTruthfulAndActionable(): void
    {
        $sentence = $this->renderedSentence();

        // 1. what happened — a temporary, safety-driven pause
        self::assertMatchesRegularExpression('/paus(e|ed)/i', $sentence);
        // 2. what it did NOT mean — nothing was declared negative
        self::assertMatchesRegularExpression('/not an NFT collection|no collection family/i', $sentence);
        // 3. what to do — wait, do not hammer Continue
        self::assertMatchesRegularExpression('/wait/i', $sentence);
        self::assertStringContainsStringIgnoringCase('clears on its own', $sentence);
    }

    /** ⚠ It must not leak internals the operator cannot act on. */
    public function testTheOperatorSentenceLeaksNothing(): void
    {
        $sentence = $this->renderedSentence();

        foreach ([
            'circuit breaker',      // internal mechanism name
            'OnchainCircuitBreaker',
            'recordFailure',
            '_bcc_cb_counter',
            'transient',
            'FAILURE_THRESHOLD',
            'Exception',
            'stack',
            'http',
            'Authorization',
            'Bearer',
            'rest.cosmos.directory',
            'wp_options',
            'SELECT',
        ] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $sentence,
                "operator prose must not expose '{$forbidden}'"
            );
        }

        // No raw counters: the sentence must contain no bare integers.
        self::assertDoesNotMatchRegularExpression('/\b\d+\b/', $sentence);
    }

    /**
     * ⚠ THE GENERIC REFUSAL MUST STOP CLAIMING THE BREAKER.
     *
     * It used to list "circuit breaker open" among its causes. Now that the
     * breaker has its own token, leaving that clause would keep both
     * sentences ambiguous — the exact defect being fixed.
     */
    public function testTheGenericRefusalNoLongerClaimsTheBreaker(): void
    {
        $generic = $this->renderedSentence(CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE);

        self::assertStringNotContainsStringIgnoringCase('circuit', $generic);
        self::assertStringNotContainsStringIgnoringCase('breaker', $generic);
        self::assertStringContainsStringIgnoringCase('paused', $generic);
    }

    /**
     * Read the shipped sentence out of the admin page's own source.
     *
     * ⚠ The page is a WordPress admin surface and cannot be instantiated
     * here, so the assertion is made against the source of the one method
     * that produces it — and against a slice bounded by the case label, so
     * a passing test cannot be reading some other branch's prose.
     */
    private function renderedSentence(string $case = CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN): string
    {
        $file = __DIR__ . '/../../app/Domain/Onchain/Admin/NftDiscoveryPage.php';
        self::assertFileExists($file);
        $src = (string) file_get_contents($file);
        self::assertGreaterThan(1000, strlen($src), 'anti-vacuity: the page source must be real');

        $needle = "case CosmwasmPassStopReason::"
            . ($case === CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN
                ? 'PROVIDER_CIRCUIT_OPEN'
                : 'CHAIN_REFUSED_TO_PREPARE')
            . ':';
        $start = strpos($src, $needle);
        self::assertNotFalse($start, "the {$needle} branch must exist");

        $rest = substr($src, $start + strlen($needle));
        $end  = strpos($rest, 'case CosmwasmPassStopReason::');
        $slice = $end === false ? $rest : substr($rest, 0, $end);

        // Keep only the quoted string literals in that branch.
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $slice, $m);
        $sentence = str_replace("\\'", "'", implode(' ', $m[1]));

        // ⚠ The source concatenates across lines, so rejoining the literals
        // leaves doubled spaces at every seam. Collapse them, or an
        // assertion about the PROSE would be failing on a formatting
        // artefact of how the string happens to be wrapped in the file.
        $sentence = trim((string) preg_replace('/\s+/', ' ', $sentence));

        self::assertNotSame('', $sentence, 'anti-vacuity: the branch must contain prose');

        return $sentence;
    }
}
