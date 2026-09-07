<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What a PROBABLE family may and may not reach — against the REAL classifier.
 *
 * ⚠ NO STUBS HERE, DELIBERATELY. `discovery-progress-stubs.php` fakes
 * `CosmwasmClassifier` down to a VERSION constant, so assertions about the
 * classifier's own predicates made under that stub would test the stub. This
 * file loads the production class.
 *
 * ── THE BOUNDARY PR 7.5 CONFIRMED RATHER THAN CHANGED ───────────────────
 * A probable family may produce an UNVERIFIED admin-review candidate. It may
 * never verify a collection, provision a community, create a gate, grant a
 * membership, or appear publicly as confirmed. Verified on staging
 * 2026-09-07: the one probable family had `collection_row_written = 0`, and
 * all eight chain-8 collection rows traced to `confirmed_cw721` contracts.
 */
#[CoversClass(CosmwasmClassifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProbableClassificationBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', __DIR__ . '/');
        }
    }

    /**
     * Source with comments removed, via the PHP TOKENIZER.
     *
     * ⚠ NOT A REGEX. The obvious block-comment-plus-line-comment pattern
     * also matches the double slash inside every `'https://…'` literal and
     * deletes the rest of that line — it mangled this file's subject so badly
     * that an assertion failed against correct code, and it would have made
     * the not-contains assertions VACUOUSLY green. `token_get_all()` knows
     * what a comment is; a regex does not.
     */
    private static function codeWithoutComments(string $relative): string
    {
        $src  = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        $out  = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        // A sanity floor: stripping must not have eaten the file.
        self::assertGreaterThan(1000, strlen($out), $relative . ' stripped to nothing');

        return $out;
    }

    /**
     * ⚠ PROBABLE IS SETTLED FOR THE SCANNER — established behaviour this PR
     * preserves rather than introduces. A probable family is not swept again
     * merely because a human has not looked at it yet.
     */
    public function testProbableIsSettledForRoutineScannerWork(): void
    {
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::PROBABLE, 0));
        self::assertFalse(CosmwasmClassifier::isRetryable(CosmwasmClassifier::CONFIRMED, 0));
        self::assertTrue(CosmwasmClassifier::isRetryable(CosmwasmClassifier::INCONCLUSIVE, 0));
        self::assertTrue(CosmwasmClassifier::isRetryable(CosmwasmClassifier::UNREACHABLE, 0));
    }

    /** ⚠ Only a NEGATIVE verdict is terminal. Probable is not a verdict. */
    public function testOnlyTheNegativeVerdictIsTerminal(): void
    {
        self::assertTrue(CosmwasmClassifier::isTerminal(CosmwasmClassifier::NOT_CW721));
        self::assertFalse(CosmwasmClassifier::isTerminal(CosmwasmClassifier::PROBABLE));
        self::assertFalse(CosmwasmClassifier::isTerminal(CosmwasmClassifier::CONFIRMED));
        self::assertFalse(CosmwasmClassifier::isTerminal(CosmwasmClassifier::UNREACHABLE));
    }

    /**
     * ⚠ A BREAKER-STOPPED SESSION MUST NOT DEMOTE UNRESOLVED WORK.
     *
     * `temporarily_unreachable` stays retryable and stays non-terminal, so a
     * provider outage — the exact thing that ended run 5 — can never turn an
     * unanswered family into "this is not an NFT collection".
     */
    public function testAnUnreachableFamilyNeverBecomesANegativeVerdict(): void
    {
        self::assertNotSame(CosmwasmClassifier::NOT_CW721, CosmwasmClassifier::UNREACHABLE);
        self::assertFalse(CosmwasmClassifier::isTerminal(CosmwasmClassifier::UNREACHABLE));
        self::assertTrue(
            CosmwasmClassifier::isRetryable(CosmwasmClassifier::UNREACHABLE, CosmwasmClassifier::MAX_RETRIES - 1),
            'still retryable below the cap'
        );
        self::assertFalse(
            CosmwasmClassifier::isRetryable(CosmwasmClassifier::UNREACHABLE, CosmwasmClassifier::MAX_RETRIES),
            'exhausted, but still NOT a negative verdict'
        );
    }

    /**
     * Probable counts as "is (probably) a CW-721" for EMISSION, which is what
     * makes it an admin-review candidate — and nothing more.
     *
     * ⚠ This predicate gates emitting an UNVERIFIED row. It is not consulted
     * by verification or provisioning, neither of which discovery can reach:
     * {@see \BCC\Trust\Onchain\Services\CosmwasmDiscoveryService::emitCollections()}
     * sets no `is_verified` and calls no provisioning service.
     */
    public function testProbableIsEmittableAsACandidate(): void
    {
        self::assertTrue(CosmwasmClassifier::isCw721(CosmwasmClassifier::CONFIRMED));
        self::assertTrue(CosmwasmClassifier::isCw721(CosmwasmClassifier::PROBABLE));
        self::assertFalse(CosmwasmClassifier::isCw721(CosmwasmClassifier::NOT_CW721));
        self::assertFalse(CosmwasmClassifier::isCw721(CosmwasmClassifier::INCONCLUSIVE));
        self::assertFalse(CosmwasmClassifier::isCw721(CosmwasmClassifier::UNREACHABLE));
    }

    /**
     * ⚠ DISCOVERY CANNOT VERIFY AND CANNOT PROVISION — asserted against the
     * source, because the guarantee is the ABSENCE of a call.
     *
     * A behavioural test cannot prove a negative here without a full WP
     * stack; what it can prove is that the emission path contains no
     * assignment to `is_verified` and no provisioning call at all.
     */
    public function testTheEmissionPathNeitherVerifiesNorProvisions(): void
    {
        foreach ([
            'app/Domain/Onchain/Services/CosmwasmDiscoveryService.php',
            'app/Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php',
        ] as $relative) {
            $code = self::codeWithoutComments($relative);

            foreach ([
                'is_verified =',
                "'is_verified' =>",
                'markVerified',
                'provisionCommunity',
                'GatedGroupProvisioningService',
                'PeepSoGroupWriter',
                'member_join',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    $relative . ' must not be able to ' . $forbidden
                );
            }
        }
    }

    /**
     * ⚠ EVERY MARKET FIELD IS PINNED NULL BY DISCOVERY.
     *
     * BCC is never about price — a STORAGE boundary, not just a display one.
     *
     * ⚠ The invariant is NOT "the field name is absent". The emitter names
     * each money column and assigns literal `null`, which is stronger: it
     * makes the emptiness explicit and reviewable at the write site instead
     * of relying on a column default nobody re-reads. A first draft of this
     * test asserted absence and failed against correct code — worth keeping
     * as a caution, because "the string isn't there" and "the value can't be
     * set" are different claims.
     */
    public function testTheEmissionPathPinsEveryMarketFieldToNull(): void
    {
        $code = self::codeWithoutComments('app/Domain/Onchain/Services/CosmwasmDiscoveryService.php');

        foreach ([
            'floor_price',
            'floor_currency',
            'total_volume',
            'listed_percentage',
            'royalty_percentage',
            'unique_holders',
        ] as $money) {
            // ⚠ CAPTURE THE VALUE; DO NOT NEGATIVE-LOOKAHEAD IT. The obvious
            // pattern `'field'\s*=>\s*(?!null)` is ALWAYS true, because the
            // `\s*` backtracks to zero width and the lookahead is then
            // evaluated against a SPACE rather than against the value. It
            // failed against correct code here — and, worse, it would have
            // passed against wrong code written without whitespace.
            $found = preg_match_all(
                "/'" . preg_quote($money, '/') . "'\s*=>\s*([^,\r\n]+)/",
                $code,
                $matches
            );

            self::assertGreaterThan(0, $found, $money . ' must be written explicitly by the emitter');

            foreach ($matches[1] as $assigned) {
                self::assertSame('null', trim($assigned), $money . ' must be pinned null, never a value');
            }
        }
    }
}
