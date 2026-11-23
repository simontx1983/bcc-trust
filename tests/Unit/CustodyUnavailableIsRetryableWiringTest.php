<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-503 UX: an owned-count read that did not run is RETRYABLE on both
 * custody-acquiring routes, not a permanent refusal.
 *
 * `unavailable` means we could not COUNT, not that the member is
 * ineligible. A 403 tells a client to stop trying, and on create the
 * generic copy ("You can't create a community right now.") reads as a
 * permanent no for a transient server fault.
 *
 * ⚠⚠ WHY THIS IS A SOURCE PIN. The transfer half has real behavioural
 * coverage — {@see CommunityCustodyServiceTest::testAnUnreadableReceiverCountIsRetryableAndNotCollapsed}
 * drives the service and asserts the wire shape. The CREATE half has none:
 * its decision is made in a private static of a REST endpoint and this repo
 * has no harness that drives `MyGroupsEndpoint`. So the create half is
 * pinned on source text here, which proves the code SAYS it and not that it
 * DOES it — the same honest limit `FeedReadFailureWiringTest` states for
 * F-275. Recorded as a gap rather than implied coverage.
 *
 * ⭐ What it does catch, and the reason it is worth having: the create gate
 * is answered at TWO sites — the pre-lock check and the authoritative
 * recheck inside the per-user lock. A reason handled in one and not the
 * other is invisible from the route's happy path, because the second site
 * only runs once the first has passed.
 */
final class CustodyUnavailableIsRetryableWiringTest extends TestCase
{
    private const ENDPOINT = 'app/Domain/Core/REST/MyGroupsEndpoint.php';
    private const SERVICE  = 'app/Domain/Core/Services/CommunityCustodyService.php';

    private string $endpoint;
    private string $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoint = self::code(self::ENDPOINT);
        $this->service  = self::code(self::SERVICE);
    }

    /**
     * A file's PHP with comments and docblocks REMOVED.
     *
     * ⚠ Load-bearing: both files EXPLAIN this change in prose that names
     * the very strings asserted below, so a plain substring search over the
     * raw text would pass on the explanation alone. A comment that names a
     * mechanism is not a use of it.
     */
    private static function code(string $relPath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relPath;
        self::assertFileExists($path, $relPath . ' moved; this pin is looking at nothing');

        $out = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }
        return $out;
    }

    /**
     * ⭐ BOTH create denial sites go through the one helper: the definition
     * plus exactly two call sites.
     *
     * If someone answers one site inline again, the count changes and this
     * fails — which is the regression the two-site shape invites.
     */
    public function testBothCreateDenialSitesUseTheSharedHelper(): void
    {
        self::assertSame(
            3,
            substr_count($this->endpoint, 'createDenyResponse('),
            'expected one definition and exactly two call sites for the create denial helper'
        );
    }

    /** The helper answers `unavailable` as a retryable 503. */
    public function testTheCreateHelperMapsUnavailableToARetryable503(): void
    {
        $at = strpos($this->endpoint, "\$reason === 'unavailable'");
        self::assertNotFalse($at, 'the create helper no longer special-cases an unreadable count');

        $branch = substr($this->endpoint, $at, 300);
        self::assertStringContainsString("'bcc_unavailable'", $branch);
        self::assertStringContainsString('503', $branch);
    }

    /**
     * ⚠ And the transfer route MAPS that code, or it silently becomes a
     * 400. The status `match` has `default => 400`, so a service returning
     * a code the map does not know tells the client its request was
     * malformed when nothing was wrong with it.
     */
    public function testTheTransferRouteMapsTheRetryableCodeTo503(): void
    {
        self::assertMatchesRegularExpression(
            "/'bcc_unavailable'\s*=>\s*503/",
            $this->endpoint,
            'the transfer status map would answer an unreadable count as 400'
        );
    }

    /**
     * On transfer the retryable branch must come BEFORE the generic
     * collapse, or `unavailable` is answered as `receiver_ineligible` —
     * a claim about the receiver that we have no basis for.
     */
    public function testTheTransferRetryableBranchPrecedesTheGenericCollapse(): void
    {
        // ⚠ SCOPED TO STEP 6 DELIBERATELY. `receiver_ineligible` is
        // returned TWICE in this service: once for the read-only-member
        // case in step 5, and once for the capability collapse in step 6.
        // An unscoped search compares against the step-5 one and fails on
        // correct code — which is exactly what it did when first written.
        $step6 = strpos($this->service, "'receive_community'");
        self::assertNotFalse($step6, 'the receiver capability check moved; this pin needs updating');

        $retryable = strpos($this->service, "'unavailable'", $step6);
        $collapse  = strpos($this->service, "'receiver_ineligible'", $step6);

        self::assertNotFalse($retryable, 'the retryable branch is gone');
        self::assertNotFalse($collapse, 'the step-6 collapse moved; this pin needs updating');
        self::assertLessThan(
            $collapse,
            $retryable,
            'an unreadable count is collapsed into receiver_ineligible before it can be answered as retryable'
        );
    }
}
