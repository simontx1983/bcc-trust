<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\ValueObjects\CosmwasmEnumerationFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Enumeration failures must leave a bounded, honest trace — and only that.
 *
 * ── WHAT WAS MISSING ────────────────────────────────────────────────────
 * When the chain-8 circuit opened on 2026-09-08 after eight consecutive
 * failures in a 23-second window, `wp_bcc_chain_checkpoints.cw_last_error`
 * was NULL. The code-tail path incremented the breaker and recorded nothing
 * at all; the code-page path stored a PROVIDER-CHOSEN sentence. The audit
 * therefore had to report the HTTP status of the failures as unknown.
 *
 * ── AND WHAT MUST NEVER BE STORED ───────────────────────────────────────
 * The fix must not swap one problem for a worse one. This column is rendered
 * to an administrator, so a remote party must never be able to put text in
 * it. The mapper reads two structured facts and cannot see the prose, which
 * is what makes the attacker-shaped tests below pass by construction rather
 * than by filtering.
 */
#[CoversClass(CosmwasmEnumerationFailure::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EnumerationTelemetryTest extends TestCase
{
    // ── the mapping ─────────────────────────────────────────────────────

    /** @return list<array{string, int, string}> */
    public static function mappings(): array
    {
        return [
            'rate limited'          => [CosmwasmClassifier::KIND_NODE_ERROR, 429, CosmwasmEnumerationFailure::RATE_LIMITED],
            '500 server error'      => [CosmwasmClassifier::KIND_NODE_ERROR, 500, CosmwasmEnumerationFailure::HTTP_5XX],
            '503 unavailable'       => [CosmwasmClassifier::KIND_NODE_ERROR, 503, CosmwasmEnumerationFailure::HTTP_5XX],
            '504 gateway timeout'   => [CosmwasmClassifier::KIND_TRANSPORT, 504, CosmwasmEnumerationFailure::HTTP_5XX],
            'wire failure'          => [CosmwasmClassifier::KIND_TRANSPORT, 0, CosmwasmEnumerationFailure::TRANSPORT],
            'unreadable 200'        => [CosmwasmClassifier::KIND_MALFORMED, 200, CosmwasmEnumerationFailure::MALFORMED_JSON],
            'not found'             => [CosmwasmClassifier::KIND_NOT_FOUND, 404, CosmwasmEnumerationFailure::UNEXPECTED_RESPONSE],
            'bad request'           => [CosmwasmClassifier::KIND_HTTP_4XX, 400, CosmwasmEnumerationFailure::UNEXPECTED_RESPONSE],
            'forbidden'             => [CosmwasmClassifier::KIND_HTTP_4XX, 403, CosmwasmEnumerationFailure::UNEXPECTED_RESPONSE],
        ];
    }

    #[DataProvider('mappings')]
    public function testResultsMapToBoundedCodes(string $kind, int $code, string $expected): void
    {
        self::assertSame($expected, CosmwasmEnumerationFailure::fromResult($kind, $code));
    }

    /** 429 wins over the generic 4xx branch. */
    public function testRateLimitingIsDistinguishedFromOtherClientErrors(): void
    {
        self::assertSame(
            CosmwasmEnumerationFailure::RATE_LIMITED,
            CosmwasmEnumerationFailure::fromResult(CosmwasmClassifier::KIND_HTTP_4XX, 429)
        );
        self::assertNotSame(
            CosmwasmEnumerationFailure::RATE_LIMITED,
            CosmwasmEnumerationFailure::fromResult(CosmwasmClassifier::KIND_HTTP_4XX, 400)
        );
    }

    /** Every produced code is a member of the bounded vocabulary. */
    public function testEveryProducedCodeIsValid(): void
    {
        foreach ([0, 200, 204, 301, 400, 403, 404, 418, 429, 500, 502, 503, 504] as $status) {
            foreach ([
                CosmwasmClassifier::KIND_NONE,
                CosmwasmClassifier::KIND_TRANSPORT,
                CosmwasmClassifier::KIND_NODE_ERROR,
                CosmwasmClassifier::KIND_MALFORMED,
                CosmwasmClassifier::KIND_HTTP_4XX,
                CosmwasmClassifier::KIND_NOT_FOUND,
                CosmwasmClassifier::KIND_QUERY_UNSUPPORTED,
            ] as $kind) {
                $out = CosmwasmEnumerationFailure::fromResult($kind, $status);
                self::assertTrue(
                    CosmwasmEnumerationFailure::isValid($out),
                    "kind={$kind} status={$status} produced an out-of-vocabulary token"
                );
            }
        }
    }

    /**
     * ⚠ NEVER GUESSED. `timeout` and `dns` exist in the vocabulary so the
     * column can carry them, but nothing may emit them until a real signal
     * distinguishes them — otherwise the field states a diagnosis nobody
     * measured, which is the exact failure the audit could not recover from.
     */
    public function testTimeoutAndDnsAreNeverFabricated(): void
    {
        $produced = [];
        foreach ([0, 200, 400, 404, 429, 500, 503, 504] as $status) {
            foreach (CosmwasmEnumerationFailure::codes() as $ignored) {
                foreach ([
                    CosmwasmClassifier::KIND_TRANSPORT,
                    CosmwasmClassifier::KIND_NODE_ERROR,
                    CosmwasmClassifier::KIND_MALFORMED,
                    CosmwasmClassifier::KIND_HTTP_4XX,
                ] as $kind) {
                    $produced[CosmwasmEnumerationFailure::fromResult($kind, $status)] = true;
                }
            }
        }

        self::assertArrayNotHasKey(CosmwasmEnumerationFailure::TIMEOUT, $produced);
        self::assertArrayNotHasKey(CosmwasmEnumerationFailure::DNS, $produced);
    }

    // ── attacker-shaped negatives ───────────────────────────────────────

    /**
     * The mapper's signature cannot carry prose, so hostile input has
     * nowhere to enter. These assert the property, not a filter.
     *
     * @return list<array{string}>
     */
    public static function hostileKinds(): array
    {
        return [
            ['<script>alert(1)</script>'],
            ["'; DROP TABLE wp_bcc_chains; --"],
            ['Bearer sk_live_51H8fakeTOKENvalue'],
            ['https://user:hunter2@rest.example.com/cosmwasm/wasm/v1/code'],
            ['Authorization: Basic YWRtaW46cGFzc3dvcmQ='],
            ['/home/u125583044/domains/bluecollarcrypto.io/public_html/wp-config.php'],
            ["Error parsing into type foo::QueryMsg: unknown variant `num_tokens`"],
            [str_repeat('A', 5000)],
            ["line one\nline two\r\nline three"],
            ['{"message":"contract controlled prose"}'],
        ];
    }

    #[DataProvider('hostileKinds')]
    public function testHostileInputCannotBecomeStoredTelemetry(string $hostile): void
    {
        foreach ([0, 200, 400, 429, 500] as $status) {
            $out = CosmwasmEnumerationFailure::fromResult($hostile, $status);

            self::assertTrue(
                CosmwasmEnumerationFailure::isValid($out),
                'an unrecognised kind must still yield a bounded token'
            );
            self::assertStringNotContainsString($hostile, $out);
            // The token vocabulary is lowercase ASCII words and underscores.
            self::assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $out);
        }
    }

    /** The whole vocabulary is short, lowercase and free of separators. */
    public function testTheVocabularyIsBoundedAndInert(): void
    {
        foreach (CosmwasmEnumerationFailure::codes() as $code) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]{1,32}$/', $code);
            self::assertLessThanOrEqual(32, strlen($code), 'must fit a 255-char column many times over');
        }
        self::assertCount(
            count(array_unique(CosmwasmEnumerationFailure::codes())),
            CosmwasmEnumerationFailure::codes(),
            'no duplicate tokens'
        );
    }

    /** Only the vocabulary validates — nothing else may reach the column. */
    public function testIsValidRejectsEverythingElse(): void
    {
        foreach ([
            '',
            ' ',
            'RATE_LIMITED',
            'rate_limited ',
            'http_500',
            'unknown variant',
            '<script>',
            "rate_limited'; --",
        ] as $bad) {
            self::assertFalse(CosmwasmEnumerationFailure::isValid($bad), "'{$bad}' must not validate");
        }

        foreach (CosmwasmEnumerationFailure::codes() as $good) {
            self::assertTrue(CosmwasmEnumerationFailure::isValid($good));
        }
    }

    // ── operator prose ──────────────────────────────────────────────────

    /** Every token has a sentence, and none of them echoes upstream text. */
    public function testEveryCodeHasBoundedOperatorProse(): void
    {
        foreach (CosmwasmEnumerationFailure::codes() as $code) {
            $sentence = CosmwasmEnumerationFailure::sentence($code);

            self::assertNotSame('', trim($sentence));
            self::assertLessThanOrEqual(120, strlen($sentence));
            self::assertStringNotContainsString('<', $sentence);
            self::assertStringNotContainsString('http', strtolower($sentence));
        }
    }

    /** An unknown token degrades to a sentence we can stand behind. */
    public function testAnUnknownTokenDoesNotEchoItself(): void
    {
        $sentence = CosmwasmEnumerationFailure::sentence('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $sentence);
        self::assertNotSame('', trim($sentence));
    }
}
