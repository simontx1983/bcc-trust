<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the CW-721 classification state machine.
 *
 * The classifier is PURE — no transport, no WordPress, no database — so
 * this suite runs in-process with zero stubs. That separation is the
 * point: the decision rules are the part that must never drift, and they
 * are testable without a single mocked HTTP call.
 *
 * THE LIVE FIXTURE. Every message string below was captured from the
 * Jackal chain (`https://rest.cosmos.directory/jackal`). It is the
 * fixture because it contains the exact failure this design exists to
 * prevent: code 1 answers with a NODE error and code 3 answers with a
 * QUERY-VARIANT error, and both arrive as a non-200 carrying the same
 * `{"code":3,"message":"…"}` envelope. Treating them alike would
 * permanently settle a live CW-721 family as `not_cw721` on a node
 * hiccup — and `not_cw721` is never routinely revisited.
 *
 *   code 1  "Numbered Collection"      Error calling the VM: Cache err…
 *                                      → temporarily_unreachable
 *   code 2  "Event SESHIVERSARY"       num_tokens 81 + contract_info
 *                                      → confirmed_cw721
 *   code 3  "Ticket-Minter-…"          Error parsing into type intra_m…
 *                                      → not_cw721   (the minter false-positive class)
 *   code 4  "bindings contract-owned"  Error parsing into type canine_…
 *                                      → not_cw721
 *   code 5  "bindings factory"         Error parsing into type binding…
 *                                      → not_cw721
 */
#[CoversClass(CosmwasmClassifier::class)]
final class CosmwasmClassifierTest extends TestCase
{
    // ── Live Jackal message fixtures ────────────────────────────────────

    private const JACKAL_CODE_1_VM   = 'Error calling the VM: Cache error: Error opening Wasm file for reading';
    private const JACKAL_CODE_3_MSG  = 'Error parsing into type intra_mint::msg::QueryMsg: unknown variant `num_tokens`';
    private const JACKAL_CODE_4_MSG  = 'Error parsing into type canine_bindings::msg::QueryMsg: unknown variant `num_tokens`';
    private const JACKAL_CODE_5_MSG  = 'Error parsing into type bindings_factory::msg::QueryMsg: unknown variant `num_tokens`';

    /** @return array{probe: string, ok: bool, kind: string, excerpt: string} */
    private static function ok(string $probe): array
    {
        return ['probe' => $probe, 'ok' => true, 'kind' => CosmwasmClassifier::KIND_NONE, 'excerpt' => ''];
    }

    /** @return array{probe: string, ok: bool, kind: string, excerpt: string} */
    private static function refused(string $probe, string $message, int $httpCode = 400): array
    {
        return [
            'probe'   => $probe,
            'ok'      => false,
            'kind'    => CosmwasmClassifier::errorKindFromMessage($message, $httpCode),
            'excerpt' => CosmwasmClassifier::sanitizeExcerpt($message),
        ];
    }

    // ── (a) the error discriminator ─────────────────────────────────────

    public function testParseErrorIsDecisiveNegativeEvidence(): void
    {
        // The message, not the status code, is what separates these.
        self::assertSame(
            CosmwasmClassifier::KIND_QUERY_UNSUPPORTED,
            CosmwasmClassifier::errorKindFromMessage(self::JACKAL_CODE_3_MSG, 400)
        );
        self::assertSame(
            CosmwasmClassifier::KIND_QUERY_UNSUPPORTED,
            CosmwasmClassifier::errorKindFromMessage(self::JACKAL_CODE_4_MSG, 400)
        );
        self::assertSame(
            CosmwasmClassifier::KIND_QUERY_UNSUPPORTED,
            CosmwasmClassifier::errorKindFromMessage(self::JACKAL_CODE_5_MSG, 400)
        );
        self::assertTrue(CosmwasmClassifier::isDecisive(CosmwasmClassifier::KIND_QUERY_UNSUPPORTED));
    }

    public function testVmErrorIsNodeSideAndNeverDecisive(): void
    {
        self::assertSame(
            CosmwasmClassifier::KIND_NODE_ERROR,
            CosmwasmClassifier::errorKindFromMessage(self::JACKAL_CODE_1_VM, 400)
        );
        // NOT decisive — this is the guard against a node hiccup being
        // recorded as "this contract is not an NFT."
        self::assertFalse(CosmwasmClassifier::isDecisive(CosmwasmClassifier::KIND_NODE_ERROR));
    }

    public function testWasmdPrefixDoesNotDefeatTheMatch(): void
    {
        // Real wasmd wraps the clause; matching a bare PREFIX of the whole
        // body would miss it, which is why the token is matched inside the
        // `message` field.
        self::assertSame(
            CosmwasmClassifier::KIND_QUERY_UNSUPPORTED,
            CosmwasmClassifier::errorKindFromMessage(
                'query wasm contract failed: Error parsing into type sg721::QueryMsg: unknown variant `num_tokens`, expected one of `config`',
                400
            )
        );
        self::assertSame(
            CosmwasmClassifier::KIND_NODE_ERROR,
            CosmwasmClassifier::errorKindFromMessage(
                'query wasm contract failed: Error calling the VM: Cache error',
                400
            )
        );
    }

    public function testStatusCodeFallbacksWhenTheMessageIsUnhelpful(): void
    {
        self::assertSame(CosmwasmClassifier::KIND_NODE_ERROR, CosmwasmClassifier::errorKindFromMessage('', 502));
        self::assertSame(CosmwasmClassifier::KIND_NODE_ERROR, CosmwasmClassifier::errorKindFromMessage('', 501));
        self::assertSame(CosmwasmClassifier::KIND_NOT_FOUND, CosmwasmClassifier::errorKindFromMessage('', 404));
        self::assertSame(CosmwasmClassifier::KIND_HTTP_4XX, CosmwasmClassifier::errorKindFromMessage('', 400));
        self::assertSame(CosmwasmClassifier::KIND_MALFORMED, CosmwasmClassifier::errorKindFromMessage('', 200));
    }

    public function testEvidenceExcerptIsSanitizedAndCapped(): void
    {
        $raw = "line one\n\tline two\x00 tail " . str_repeat('x', 600);

        $excerpt = CosmwasmClassifier::sanitizeExcerpt($raw);

        self::assertLessThanOrEqual(CosmwasmClassifier::EXCERPT_MAX, mb_strlen($excerpt));
        self::assertStringNotContainsString("\n", $excerpt);
        self::assertStringNotContainsString("\x00", $excerpt);
        self::assertStringStartsWith('line one line two tail', $excerpt);
    }

    // ── (b) the Jackal expected-outcome fixture ─────────────────────────

    public function testJackalCode1SettlesTemporarilyUnreachableNotNotCw721(): void
    {
        // THE REGRESSION THAT MATTERS. A VM cache error on every probe is
        // the node failing, not the contract answering. Recording
        // not_cw721 here would be permanent — the family would never be
        // routinely revisited.
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_1_VM),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_1_VM),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_1_VM),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertNotSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
        self::assertSame('node_unreachable', $verdict['reason']);
        self::assertTrue(CosmwasmClassifier::isRetryable($verdict['classification'], 0));
    }

    public function testJackalCode2IsConfirmedCw721(): void
    {
        $verdict = CosmwasmClassifier::classify([
            self::ok(CosmwasmClassifier::PROBE_NUM_TOKENS),
            self::ok(CosmwasmClassifier::PROBE_CONTRACT_INFO),
        ]);

        self::assertSame(CosmwasmClassifier::CONFIRMED, $verdict['classification']);
        self::assertSame('num_tokens_and_info', $verdict['reason']);
        self::assertStringContainsString(CosmwasmClassifier::PROBE_NUM_TOKENS, $verdict['probes_ok']);
    }

    public function testJackalCode3MinterIsNotCw721(): void
    {
        // The minter false-positive class the owner called out: it answers
        // `config`/`minter`, so an info-only probe set could mistake it for
        // a collection. num_tokens is what refuses it.
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_3_MSG),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_3_MSG),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_3_MSG),
        ]);

        self::assertSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
        self::assertSame('no_cw721_queries', $verdict['reason']);
    }

    public function testJackalCode4BindingsIsNotCw721(): void
    {
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_4_MSG),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_4_MSG),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_4_MSG),
        ]);

        self::assertSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
    }

    public function testJackalCode5FactoryIsNotCw721(): void
    {
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_5_MSG),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_5_MSG),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_5_MSG),
        ]);

        self::assertSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
    }

    // ── (c) the rest of the state machine ───────────────────────────────

    public function testSg721StyleContractIsConfirmedViaTheModernVariant(): void
    {
        // SG721 (Cosmos Hub code 434) REJECTS contract_info outright and
        // answers get_collection_info_and_extension — measured live.
        $verdict = CosmwasmClassifier::classify([
            self::ok(CosmwasmClassifier::PROBE_NUM_TOKENS),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, 'Error parsing into type sg721::QueryMsg: unknown variant `contract_info`'),
            self::ok(CosmwasmClassifier::PROBE_COLLECTION_INFO),
        ]);

        self::assertSame(CosmwasmClassifier::CONFIRMED, $verdict['classification']);
    }

    public function testNumTokensOnlyIsProbableNotConfirmed(): void
    {
        $verdict = CosmwasmClassifier::classify([
            self::ok(CosmwasmClassifier::PROBE_NUM_TOKENS),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_3_MSG),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_3_MSG),
        ]);

        self::assertSame(CosmwasmClassifier::PROBABLE, $verdict['classification']);
        self::assertSame('num_tokens_only', $verdict['reason']);
    }

    public function testInfoOnlyIsProbableNotConfirmed(): void
    {
        // Names itself but cannot count tokens — exactly the shape a
        // minter with collection-shaped state has. Probable at best.
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_3_MSG),
            self::ok(CosmwasmClassifier::PROBE_CONTRACT_INFO),
        ]);

        self::assertSame(CosmwasmClassifier::PROBABLE, $verdict['classification']);
        self::assertSame('info_only', $verdict['reason']);
    }

    public function testPartialEvidenceWithANodeErrorDoesNotGuess(): void
    {
        // num_tokens answered; the info variants blew up node-side. We do
        // NOT downgrade to probable on a half-answer — we retry.
        $verdict = CosmwasmClassifier::classify([
            self::ok(CosmwasmClassifier::PROBE_NUM_TOKENS),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_1_VM),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_1_VM),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame('partial_evidence_node_unreachable', $verdict['reason']);
    }

    public function testMixedUnsupportedAndNodeErrorNeverSettlesNegative(): void
    {
        // Two decisive refusals plus one node error. If rule ordering ever
        // slips, this becomes not_cw721 — permanently.
        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, self::JACKAL_CODE_1_VM),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, self::JACKAL_CODE_3_MSG),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, self::JACKAL_CODE_3_MSG),
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
    }

    public function testTransportFailureIsUnreachable(): void
    {
        $verdict = CosmwasmClassifier::classify([
            [
                'probe'   => CosmwasmClassifier::PROBE_NUM_TOKENS,
                'ok'      => false,
                'kind'    => CosmwasmClassifier::KIND_TRANSPORT,
                'excerpt' => 'cURL error 28',
            ],
        ]);

        self::assertSame(CosmwasmClassifier::UNREACHABLE, $verdict['classification']);
        self::assertSame('cURL error 28', $verdict['last_error']);
    }

    public function testMissingContractIsInconclusiveNotNegative(): void
    {
        $verdict = CosmwasmClassifier::classify([
            [
                'probe'   => CosmwasmClassifier::PROBE_NUM_TOKENS,
                'ok'      => false,
                'kind'    => CosmwasmClassifier::KIND_NOT_FOUND,
                'excerpt' => 'not found',
            ],
        ]);

        self::assertSame(CosmwasmClassifier::INCONCLUSIVE, $verdict['classification']);
        self::assertSame('contract_not_found', $verdict['reason']);
    }

    public function testFamilyWithNoContractsIsInconclusiveNotNegative(): void
    {
        // ~50% of code families have ZERO contracts (measured). There is
        // nothing to probe, so there is nothing to RULE OUT either — a
        // contract could be instantiated tomorrow.
        $verdict = CosmwasmClassifier::noContractsVerdict();

        self::assertSame(CosmwasmClassifier::INCONCLUSIVE, $verdict['classification']);
        self::assertSame('no_contracts', $verdict['reason']);
        self::assertNotSame(CosmwasmClassifier::NOT_CW721, $verdict['classification']);
    }

    // ── (d) terminal + retry policy ─────────────────────────────────────

    public function testConfirmedNonNftIsNeverRetried(): void
    {
        self::assertTrue(CosmwasmClassifier::isTerminal(CosmwasmClassifier::NOT_CW721));
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::NOT_CW721, 0));
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::NOT_CW721, 5));
    }

    public function testDecidedCw721FamiliesAreNotReclassified(): void
    {
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::CONFIRMED, 0));
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::PROBABLE, 0));
    }

    public function testInconclusiveFollowsTheRetryCap(): void
    {
        for ($i = 0; $i < CosmwasmClassifier::MAX_RETRIES; $i++) {
            self::assertTrue(
                CosmwasmClassifier::isRetryable(CosmwasmClassifier::INCONCLUSIVE, $i),
                "retry {$i} should still be eligible"
            );
        }
        self::assertFalse(
            CosmwasmClassifier::isRetryable(CosmwasmClassifier::INCONCLUSIVE, CosmwasmClassifier::MAX_RETRIES)
        );
        self::assertFalse(
            CosmwasmClassifier::isRetryable(CosmwasmClassifier::UNREACHABLE, CosmwasmClassifier::MAX_RETRIES)
        );
    }

    public function testBackoffIsStagedExponentialAndCapped(): void
    {
        $previous = 0;
        for ($i = 0; $i < 12; $i++) {
            $seconds = CosmwasmClassifier::backoffSeconds($i);
            self::assertGreaterThanOrEqual($previous, $seconds, "backoff must never shrink at retry {$i}");
            $previous = $seconds;
        }

        self::assertSame(6 * 3600, CosmwasmClassifier::backoffSeconds(0));
        self::assertSame(12 * 3600, CosmwasmClassifier::backoffSeconds(1));
        self::assertSame(24 * 3600, CosmwasmClassifier::backoffSeconds(2));
        // Capped at 28 days no matter how far the counter runs.
        self::assertSame(28 * 86400, CosmwasmClassifier::backoffSeconds(30));
        self::assertSame(28 * 86400, CosmwasmClassifier::backoffSeconds(9999));
    }

    // ── (e) classifier version + checksum inheritance policy ────────────

    public function testVersionBumpNeverRequeuesSettledNegatives(): void
    {
        $requeueable = CosmwasmClassifier::requeueableClassifications();

        self::assertNotContains(CosmwasmClassifier::NOT_CW721, $requeueable);
        self::assertNotContains(CosmwasmClassifier::CONFIRMED, $requeueable);
        self::assertContains(CosmwasmClassifier::INCONCLUSIVE, $requeueable);
        self::assertContains(CosmwasmClassifier::UNREACHABLE, $requeueable);
        self::assertContains(CosmwasmClassifier::PROBABLE, $requeueable);
    }

    public function testOnlyBinaryDeterminedVerdictsAreInheritableByChecksum(): void
    {
        $inheritable = CosmwasmClassifier::inheritableClassifications();

        // These describe the BINARY, so an identical binary shares them.
        self::assertContains(CosmwasmClassifier::CONFIRMED, $inheritable);
        self::assertContains(CosmwasmClassifier::PROBABLE, $inheritable);
        self::assertContains(CosmwasmClassifier::NOT_CW721, $inheritable);
        // These describe the NODE or the SAMPLE, not the binary — they must
        // never spread to a twin.
        self::assertNotContains(CosmwasmClassifier::UNREACHABLE, $inheritable);
        self::assertNotContains(CosmwasmClassifier::INCONCLUSIVE, $inheritable);
    }

    public function testChecksumTwinVerdictCarriesNoMetadataAndNoVerification(): void
    {
        $verdict = CosmwasmClassifier::checksumTwinVerdict(CosmwasmClassifier::CONFIRMED, 434);

        self::assertSame(CosmwasmClassifier::CONFIRMED, $verdict['classification']);
        self::assertSame('checksum_twin:434', $verdict['reason']);
        // No probe evidence is fabricated, and there is nothing here that
        // could verify a collection or name one.
        self::assertSame('', $verdict['probes_ok']);
        self::assertSame('', $verdict['probes_failed']);
        self::assertArrayNotHasKey('is_verified', $verdict);
        self::assertArrayNotHasKey('collection_name', $verdict);
        self::assertArrayNotHasKey('contract_address', $verdict);
    }

    public function testEvidenceFieldsStayWithinColumnLimits(): void
    {
        $long = str_repeat('boundary ', 200);

        $verdict = CosmwasmClassifier::classify([
            self::refused(CosmwasmClassifier::PROBE_NUM_TOKENS, $long),
            self::refused(CosmwasmClassifier::PROBE_CONTRACT_INFO, $long),
            self::refused(CosmwasmClassifier::PROBE_COLLECTION_INFO, $long),
        ]);

        self::assertLessThanOrEqual(64, mb_strlen($verdict['reason']));
        self::assertLessThanOrEqual(128, mb_strlen($verdict['probes_ok']));
        self::assertLessThanOrEqual(128, mb_strlen($verdict['probes_failed']));
        self::assertLessThanOrEqual(255, mb_strlen($verdict['last_error']));
    }
}
